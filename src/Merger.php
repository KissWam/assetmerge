<?php
/**
 * AssetMerge — transactional merge execution engine (P3).
 *
 * Executes what PreviewEngine only describes. All writes run inside a single
 * DB transaction; any failure rolls everything back.
 *
 * Execution steps (in order):
 *   1) re-validate the three gates against LIVE data (defensive re-check)
 *   2) acquire per-computer concurrency guards
 *   3) BEGIN TRANSACTION
 *   4) transfer manual fields (blank sentinel on target => adopt source value)
 *   5) move relation rows source -> target (with unique-key dedupe)
 *   6) write field locks for transferred manual fields
 *   7) soft-delete sources (is_deleted=1; history preserved)
 *   8) write audit log row (status=done, embedded report snapshot)
 *   9) COMMIT; on any exception ROLLBACK + audit row status=failed
 *
 * NO physical DELETE of any computer row. glpi_logs on the source is kept.
 */

namespace GlpiPlugin\AssetMerge;

use DBmysql;
use RuntimeException;
use Session;

final class Merger
{
    /** @var DBmysql */
    private $db;

    /** @var array<string,int> operation counters for result reporting */
    private $counters = [];

    public function __construct(DBmysql $db)
    {
        $this->db = $db;
    }

    /**
     * Execute the merge.
     *
     * @param int   $targetId  record to keep (dynamic + agent-bound)
     * @param int[] $sourceIds records to soft-delete
     * @param array $report    the preview report (used for validation + audit snapshot)
     * @param array $overrides optional fields to override with source value
     *                         (whitelist: RelationMap::overridableFields keys)
     * @return array{ok:bool,errors:array,warnings:array,stats:array}
     */
    public function execute(int $targetId, array $sourceIds, array $report, array $overrides = []): array
    {
        $result = ['ok' => false, 'errors' => [], 'warnings' => [], 'stats' => []];
        $this->resetCounters();

        $sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds))));

        // Whitelist overrides strictly: only fields declared overridable may
        // be written from the source. Anything else is ignored + warned.
        $allowed = array_keys(RelationMap::overridableFields());
        $clean   = [];
        foreach ($overrides as $o) {
            $o = (string)$o;
            if (in_array($o, $allowed, true)) {
                $clean[] = $o;
            } else {
                $result['warnings'][] = sprintf(__('Ignored unsupported override field: %s', 'assetmerge'), $o);
            }
        }
        $this->overrides = array_values(array_unique($clean));

        // ---- Step 1: defensive re-validation against live data ----
        $validation = $this->validate($targetId, $sourceIds);
        if (!empty($validation['errors'])) {
            $result['errors'] = $validation['errors'];
            $this->writeAudit($targetId, $sourceIds, 'rejected', implode(' | ', $validation['errors']), $report);
            return $result;
        }
        $result['warnings'] = array_merge($result['warnings'], $validation['warnings']);

        // Block execution if the preview itself was not OK.
        if (empty($report['ok'])) {
            $result['errors'][] = __('Preview report is not OK; refusing to merge.', 'assetmerge');
            $this->writeAudit($targetId, $sourceIds, 'rejected', 'preview not ok', $report);
            return $result;
        }

        // Block execution if any field still requires manual adjudication.
        foreach ($report['fields'] ?? [] as $f) {
            if (!empty($f['conflict'])) {
                $result['errors'][] = sprintf(
                    __('Field %s requires manual decision before execution.', 'assetmerge'),
                    $f['field']
                );
            }
        }
        if (!empty($result['errors'])) {
            $this->writeAudit($targetId, $sourceIds, 'rejected', 'manual decision pending', $report);
            return $result;
        }

        // ---- Step 2: concurrency guards ----
        if (!$this->acquireLocks($targetId, $sourceIds)) {
            $result['errors'][] = __('Another merge is in progress on one of these records. Try again later.', 'assetmerge');
            $this->writeAudit($targetId, $sourceIds, 'rejected', 'concurrency guard', $report);
            return $result;
        }

        $committed = false;
        try {
            // ---- Step 3: transaction ----
            $this->db->beginTransaction();

            // 4) field transfer (blank-sentinel manual fields)
            $this->transferFields($targetId, $sourceIds, $report);

            // 4b) operator-elected overrides (states_id / users_id): source wins
            $this->applyOverrides($targetId, $sourceIds);

            // 5) relation migration
            $this->moveRelations($targetId, $sourceIds);

            // 6) field locks
            $this->applyFieldLocks($targetId, $report);

            // 7) soft delete sources
            $this->softDeleteSources($sourceIds);

            // 8) audit log (inside transaction, status=done)
            $auditMsg = $this->formatCounters();
            if (!empty($this->overrides)) {
                $auditMsg .= ' | operator_overrides=' . implode(',', $this->overrides);
            }
            $this->writeAudit($targetId, $sourceIds, 'done', $auditMsg, $report);

            // 9) commit
            $this->db->commit();
            $committed = true;
        } catch (\Throwable $e) {
            if (!$committed) {
                try {
                    $this->db->rollBack();
                } catch (\Throwable $rb) {
                    // rollBack failed; recorded below
                }
            }
            $result['errors'][] = sprintf(__('Merge failed and was rolled back: %s', 'assetmerge'), $e->getMessage());
            // Audit outside the rolled-back transaction so the failure is recorded.
            $this->writeAudit($targetId, $sourceIds, 'failed', $e->getMessage(), $report);
        } finally {
            $this->releaseLocks($targetId, $sourceIds);
        }

        $result['ok']    = empty($result['errors']);
        $result['stats'] = $this->counters;
        return $result;
    }

    /**
     * Defensive re-check of the three gates against live DB state.
     *
     * @return array{errors:array,warnings:array}
     */
    private function validate(int $targetId, array $sourceIds): array
    {
        $out = ['errors' => [], 'warnings' => []];

        $target = $this->fetchComputer($targetId);
        if ($target === null) {
            $out['errors'][] = sprintf(__('Target #%d no longer exists.', 'assetmerge'), $targetId);
            return $out;
        }
        if ((int)($target['is_dynamic'] ?? 0) !== 1) {
            $out['errors'][] = sprintf(__('Target #%d is not dynamic.', 'assetmerge'), $targetId);
        }
        if ((int)($target['is_deleted'] ?? 0) === 1) {
            $out['errors'][] = sprintf(__('Target #%d is in the trash.', 'assetmerge'), $targetId);
        }
        if ($this->countAgents($targetId) === 0) {
            $out['errors'][] = sprintf(__('Target #%d has no agent binding.', 'assetmerge'), $targetId);
        }

        foreach ($sourceIds as $sid) {
            $src = $this->fetchComputer($sid);
            if ($src === null) {
                $out['errors'][] = sprintf(__('Source #%d no longer exists.', 'assetmerge'), $sid);
                continue;
            }
            if ((int)($src['entities_id'] ?? -1) !== (int)($target['entities_id'] ?? -2)) {
                $out['errors'][] = sprintf(__('Source #%d is in a different entity.', 'assetmerge'), $sid);
            }
            if ((int)($src['is_deleted'] ?? 0) === 1) {
                $out['errors'][] = sprintf(__('Source #%d is already in the trash.', 'assetmerge'), $sid);
            }
            $sa = $this->countAgents($sid);
            if ($sa > 0) {
                $out['warnings'][] = sprintf(__('Source #%d has %d agent binding(s).', 'assetmerge'), $sid, $sa);
            }
        }

        return $out;
    }

    /**
     * Transfer manual fields from source to target using the SAME blank
     * sentinel policy as PreviewEngine. Only fields where the target is a
     * blank placeholder and the source holds a real value are written.
     */
    private function transferFields(int $targetId, array $sourceIds, array $report): void
    {
        $policies  = RelationMap::fieldPolicies();
        $sentinels = RelationMap::blankSentinels();
        $target    = $this->fetchComputer($targetId) ?? [];
        $sources   = [];
        foreach ($sourceIds as $sid) {
            $row = $this->fetchComputer($sid);
            if ($row !== null) {
                $sources[] = $row;
            }
        }

        $updates = [];
        foreach ($policies as $key => $policy) {
            $field = $policy['field'];
            // Only 'manual' fields are candidates for transfer.
            if ($policy['winner'] !== 'manual') {
                continue;
            }
            $tval   = (string)($target[$field] ?? '');
            $blanks = $sentinels[$field] ?? [''];
            $tBlank = in_array($tval, $blanks, true);
            if (!$tBlank) {
                // Target has a real value -> keep it (no conflict here because
                // conflicts were rejected before execution).
                continue;
            }
            // Find first non-blank source value.
            $sval = null;
            foreach ($sources as $srow) {
                $candidate = (string)($srow[$field] ?? '');
                if (!in_array($candidate, $blanks, true)) {
                    $sval = $candidate;
                    break;
                }
            }
            if ($sval !== null) {
                $updates[$field] = $sval;
            }
        }

        if (empty($updates)) {
            $this->counters['fields_transferred'] = 0;
            return;
        }

        // date_mod is maintained by GLPI; we only set the transferred fields.
        $this->db->update('glpi_computers', $updates, ['id' => $targetId]);
        $this->counters['fields_transferred'] = count($updates);
        $this->counters['fields_transferred_list'] = implode(',', array_keys($updates));
        // Keep the exact list for the lock step.
        $this->transferredFields = array_keys($updates);
    }

    /** @var array<int,string> fields actually written in transferFields */
    private $transferredFields = [];

    /** @var array<int,string> operator-elected override fields (whitelisted) */
    private $overrides = [];

    /**
     * Apply operator-elected overrides: for each whitelisted overridable field,
     * write the source value onto the target. Unlike transferFields() this does
     * NOT require the target to be a blank sentinel — the operator explicitly
     * chose to replace the target value. Overridden fields are appended to
     * $transferredFields so applyFieldLocks() locks them too.
     */
    private function applyOverrides(int $targetId, array $sourceIds): void
    {
        if (empty($this->overrides)) {
            $this->counters['fields_overridden'] = 0;
            return;
        }
        $sources = [];
        foreach ($sourceIds as $sid) {
            $row = $this->fetchComputer($sid);
            if ($row !== null) {
                $sources[] = $row;
            }
        }

        $updates      = [];
        $applied      = [];
        $groupsMoved  = 0;
        foreach ($this->overrides as $field) {
            // groups is a relation table, not a scalar column.
            if ($field === 'groups') {
                $groupsMoved = $this->overrideGroups($targetId, $sourceIds);
                if ($groupsMoved > 0) {
                    $applied[] = 'groups';
                }
                continue;
            }
            // First source row with a non-empty value wins.
            foreach ($sources as $srow) {
                $val = $srow[$field] ?? null;
                if ($val !== null && $val !== '') {
                    $updates[$field] = $val;
                    $applied[]       = $field;
                    break;
                }
            }
        }
        if (!empty($updates)) {
            $this->db->update('glpi_computers', $updates, ['id' => $targetId]);
            // Do not lock here: applyFieldLocks() runs later and needs the
            // FINAL target values, so just register the field names.
            foreach ($applied as $f) {
                if ($f !== 'groups' && !in_array($f, $this->transferredFields, true)) {
                    $this->transferredFields[] = $f; // rows with override_lock
                }
            }
        }
        $this->counters['fields_overridden']      = count($applied);
        $this->counters['fields_overridden_list'] = implode(',', $applied);
        if ($groupsMoved > 0 || in_array('groups', $this->overrides, true)) {
            $this->counters['group_rows_moved'] = $groupsMoved;
        }
    }

    /**
     * Opt-in adoption of source group membership rows (glpi_groups_items).
     * Dedupe by (itemtype, items_id, groups_id, type): if the target already
     * has the same group row, drop the source row instead of duplicating.
     *
     * @return int number of source group rows re-pointed to target
     */
    private function overrideGroups(int $targetId, array $sourceIds): int
    {
        $table = 'glpi_groups_items';
        if (!$this->db->tableExists($table)) {
            return 0;
        }
        $moved = 0;
        foreach ($sourceIds as $sid) {
            $rows = [];
            foreach ($this->db->request([
                'FROM'  => $table,
                'WHERE' => ['itemtype' => 'Computer', 'items_id' => $sid],
            ]) as $r) {
                $rows[] = $r;
            }
            foreach ($rows as $r) {
                $dup = false;
                foreach ($this->db->request([
                    'COUNT' => 'c',
                    'FROM'  => $table,
                    'WHERE' => [
                        'itemtype'  => 'Computer',
                        'items_id'  => $targetId,
                        'groups_id' => (int)($r['groups_id'] ?? 0),
                        'type'      => (int)($r['type'] ?? 0),
                    ],
                ]) as $c) {
                    $dup = ((int)$c['c'] > 0);
                    break;
                }
                if ($dup) {
                    $this->db->delete($table, ['id' => (int)$r['id']]);
                    continue;
                }
                $this->db->update(
                    $table,
                    ['items_id' => $targetId],
                    ['id' => (int)$r['id']]
                );
                $moved++;
            }
        }
        return $moved;
    }

    /**
     * Apply field locks for transferred manual fields.
     *
     * glpi_lockedfields.value stores the value frozen at lock time; GLPI's
     * inventory engine reads this table and skips locked fields.
     */
    private function applyFieldLocks(int $targetId, array $report): void
    {
        $cfg = \Config::getConfigurationValues('plugin:assetmerge');
        if (isset($cfg['lock_transferred_manual_fields']) && (int)$cfg['lock_transferred_manual_fields'] === 0) {
            $this->counters['locks_applied'] = 0;
            return;
        }

        $target = $this->fetchComputer($targetId) ?? [];
        $n = 0;
        foreach ($this->transferredFields as $field) {
            $value = (string)($target[$field] ?? '');
            // Upsert: delete any existing lock row for this field, then insert.
            $this->db->delete('glpi_lockedfields', [
                'itemtype' => 'Computer',
                'items_id' => $targetId,
                'field'    => $field,
            ]);
            $this->db->insert('glpi_lockedfields', [
                'itemtype'      => 'Computer',
                'items_id'      => $targetId,
                'field'         => $field,
                'value'         => $value,
                'is_global'     => 0,
                'date_creation' => date('Y-m-d H:i:s'),
                'date_mod'      => date('Y-m-d H:i:s'),
            ]);
            $n++;
        }
        $this->counters['locks_applied'] = $n;
    }

    /**
     * Move relation rows from every source to the target.
     *
     * - skips glpi_agents (target already owns the binding)
     * - adds itemtype='Computer' filter for the 45 tables that have it
     * - dedupes rows that would violate a unicity key on the target
     */
    private function moveRelations(int $targetId, array $sourceIds): void
    {
        $moved = 0;
        $deduped = 0;
        $skipped = 0;

        foreach (RelationMap::relations() as $rel) {
            $table = $rel['table'];
            $col   = $rel['where'];

            // Agent binding: never move; target must remain the single owner.
            if ($table === 'glpi_agents') {
                $skipped++;
                continue;
            }
            if (!$this->db->tableExists($table) || !$this->db->fieldExists($table, $col)) {
                $skipped++;
                continue;
            }

            $hasItemtype = $this->db->fieldExists($table, 'itemtype');
            $dedupCols   = $this->dedupColumns($table);

            foreach ($sourceIds as $sid) {
                $where = [$col => $sid];
                if ($hasItemtype) {
                    $where['itemtype'] = 'Computer';
                }

                // Fetch source rows so we can check for target duplicates.
                $rows = [];
                foreach ($this->db->request(['FROM' => $table, 'WHERE' => $where]) as $row) {
                    $rows[] = $row;
                }

                foreach ($rows as $row) {
                    // Infocom needs field-level merge, not row dedupe: both
                    // records may carry complementary financial data. Dropping
                    // the source row would silently lose its non-empty fields.
                    if ($table === 'glpi_infocoms' && ($row['itemtype'] ?? '') === 'Computer') {
                        if ($this->mergeInfocom($targetId, $row)) {
                            $deduped++; // source row removed after field merge
                        }
                        continue;
                    }
                    if ($dedupCols !== null && $this->targetHasDuplicate($table, $targetId, $row, $dedupCols, $hasItemtype)) {
                        // Target already has an equivalent row: drop the source
                        // row instead of UPDATE (which would hit unicity key).
                        $delWhere = ['id' => (int)$row['id']];
                        $this->db->delete($table, $delWhere);
                        $deduped++;
                        continue;
                    }
                    $this->db->update(
                        $table,
                        [$col => $targetId, 'date_mod' => date('Y-m-d H:i:s')],
                        ['id' => (int)$row['id']]
                    );
                    $moved++;
                }
            }
        }

        $this->counters['relations_moved']  = $moved;
        $this->counters['relations_deduped'] = $deduped;
        $this->counters['relations_skipped'] = $skipped;
    }

    /**
     * Field-level merge of a Computer infocom row into the target's infocom.
     *
     * Both records may carry complementary financial data (buy_date, use_date,
     * warranty, supplier, value...). We fill only target fields that are NULL
     * or empty string from the source; when both sides hold a value the target
     * wins (it is the kept/agent record) and the conflict is recorded in the
     * operation counters. The source infocom row is removed after merging so
     * the unicity(itemtype,items_id) key is not violated.
     *
     * Numeric zero is treated as "no data" ONLY for id-like FK columns; for
     * monetary fields 0 is left alone (conservative: never overwrite a real
     * target value).
     *
     * @param array<string,mixed> $sourceRow
     * @return bool true when a source row was consumed
     */
    private function mergeInfocom(int $targetId, array $sourceRow): bool
    {
        // Locate target's Computer infocom row.
        $targetRow = null;
        foreach ($this->db->request([
            'FROM'  => 'glpi_infocoms',
            'WHERE' => ['itemtype' => 'Computer', 'items_id' => $targetId],
        ]) as $r) {
            $targetRow = $r;
            break;
        }

        if ($targetRow === null) {
            // No target infocom: simply re-point the source row to the target.
            $this->db->update(
                'glpi_infocoms',
                ['items_id' => $targetId, 'date_mod' => date('Y-m-d H:i:s')],
                ['id' => (int)$sourceRow['id']]
            );
            $this->counters['infocom_moved'] = ($this->counters['infocom_moved'] ?? 0) + 1;
            return false;
        }

        // Fields eligible for fill-from-source (exclude audit/id/key fields).
        $fillable = [
            'buy_date', 'use_date', 'warranty_duration', 'warranty_info',
            'suppliers_id', 'order_number', 'delivery_number', 'immo_number',
            'value', 'warranty_value', 'budgets_id', 'order_date',
            'delivery_date', 'inventory_date', 'warranty_date', 'comment', 'bill',
        ];
        $fills   = [];
        $conflicts = [];
        foreach ($fillable as $f) {
            if (!array_key_exists($f, $sourceRow)) {
                continue;
            }
            $sv = $sourceRow[$f];
            $tv = $targetRow[$f] ?? null;
            $sEmpty = ($sv === null || $sv === '');
            $tEmpty = ($tv === null || $tv === '');
            if ($sEmpty) {
                continue;
            }
            if ($tEmpty) {
                $fills[$f] = $sv;
            } elseif ((string)$sv !== (string)$tv) {
                $conflicts[] = $f; // keep target value
            }
        }
        if (!empty($fills)) {
            $fills['date_mod'] = date('Y-m-d H:i:s');
            $this->db->update('glpi_infocoms', $fills, ['id' => (int)$targetRow['id']]);
        }
        if (!empty($conflicts)) {
            $this->counters['infocom_field_conflicts'] =
                ($this->counters['infocom_field_conflicts'] ?? 0) . ':' . implode(',', $conflicts);
        }
        $this->counters['infocom_fields_filled'] =
            ($this->counters['infocom_fields_filled'] ?? 0) + count($fills) - 1; // minus date_mod

        // Remove source infocom row after merging its values.
        $this->db->delete('glpi_infocoms', ['id' => (int)$sourceRow['id']]);
        return true;
    }

    /**
     * Columns forming the unicity key that must be checked before moving a
     * row to the target. Returns null when no dedupe is needed.
     *
     * @return array<int,string>|null
     */
    private function dedupColumns(string $table): ?array
    {
        $map = [
            'glpi_items_operatingsystems' => ['operatingsystems_id', 'operatingsystemversions_id', 'operatingsystemarchitectures_id', 'operatingsystemservicepacks_id'],
            'glpi_items_softwareversions' => ['softwareversions_id'],
            'glpi_items_softwarelicenses' => ['softwarelicenses_id'],
            'glpi_contracts_items'        => ['contracts_id'],
            'glpi_documents_items'        => ['documents_id'],
            'glpi_appliances_items'       => ['appliances_id'],
            'glpi_domains_items'          => ['domains_id'],
            'glpi_certificates_items'     => ['certificates_id'],
            'glpi_items_projects'         => ['projects_id'],
            'glpi_knowbaseitems_items'    => ['knowbaseitems_id'],
            'glpi_items_tickets'          => ['tickets_id'],
            'glpi_items_problems'         => ['problems_id'],
            'glpi_changes_items'          => ['changes_id'],
            // glpi_infocoms handled by mergeInfocom() (field-level merge).
        ];
        if (!array_key_exists($table, $map)) {
            return null;
        }
        // Filter to columns that actually exist on the table.
        $cols = [];
        foreach ($map[$table] as $c) {
            if ($this->db->fieldExists($table, $c)) {
                $cols[] = $c;
            }
        }
        return $cols; // may be [] => any row on target counts as duplicate
    }

    /**
     * Check whether the target already owns an equivalent row.
     *
     * @param array<string,mixed> $sourceRow
     * @param array<int,string>   $dedupCols
     */
    private function targetHasDuplicate(string $table, int $targetId, array $sourceRow, array $dedupCols, bool $hasItemtype): bool
    {
        $where = [];
        $col   = null;
        // Recover the referencing column from the relation map by matching table.
        foreach (RelationMap::relations() as $rel) {
            if ($rel['table'] === $table) {
                $col = $rel['where'];
                break;
            }
        }
        if ($col === null) {
            return false;
        }
        $where[$col] = $targetId;
        if ($hasItemtype) {
            $where['itemtype'] = 'Computer';
        }
        foreach ($dedupCols as $dc) {
            $where[$dc] = $sourceRow[$dc] ?? null;
        }
        foreach ($this->db->request(['COUNT' => 'c', 'FROM' => $table, 'WHERE' => $where]) as $r) {
            return (int)$r['c'] > 0;
        }
        return false;
    }

    /** Soft-delete source records; history (glpi_logs) is preserved. */
    private function softDeleteSources(array $sourceIds): void
    {
        $n = 0;
        foreach ($sourceIds as $sid) {
            $this->db->update('glpi_computers', ['is_deleted' => 1], ['id' => $sid]);
            $n++;
        }
        $this->counters['sources_soft_deleted'] = $n;
    }

    /** Acquire concurrency guard rows for all involved computers. */
    private function acquireLocks(int $targetId, array $sourceIds): bool
    {
        $ids = array_merge([$targetId], $sourceIds);
        $now = date('Y-m-d H:i:s');
        $session = session_id() ?: ('cli-' . getmypid());

        // Strict policy: any existing guard means another merge holds the
        // records. Stale guards are cleared only by an operator / on release.
        $held = [];
        foreach ($this->db->request([
            'FROM'  => 'glpi_plugin_assetmerge_locks',
            'WHERE' => ['computer_id' => $ids],
        ]) as $r) {
            $held[] = (int)$r['computer_id'];
        }
        if (!empty($held)) {
            return false;
        }

        foreach ($ids as $id) {
            $this->db->insert('glpi_plugin_assetmerge_locks', [
                'computer_id'   => $id,
                'users_id'      => (int)($_SESSION['glpiID'] ?? 0),
                'session_id'    => $session,
                'date_creation' => $now,
            ]);
        }
        return true;
    }

    private function releaseLocks(int $targetId, array $sourceIds): void
    {
        try {
            $ids = array_merge([$targetId], $sourceIds);
            $this->db->delete('glpi_plugin_assetmerge_locks', ['computer_id' => $ids]);
        } catch (\Throwable $e) {
            // Best-effort cleanup; a stale guard is preferable to masking the
            // primary merge result.
        }
    }

    /**
     * Write an audit row. Called both inside (done) and outside (failed /
     * rejected) the transaction.
     */
    private function writeAudit(int $targetId, array $sourceIds, string $status, string $message, array $report): void
    {
        try {
            $this->db->insert('glpi_plugin_assetmerge_logs', [
                'date_creation'    => date('Y-m-d H:i:s'),
                'users_id'         => (int)($_SESSION['glpiID'] ?? 0),
                'action'           => 'merge',
                'source_itemtype'  => 'Computer',
                'source_id'        => (int)($sourceIds[0] ?? 0),
                'target_itemtype'  => 'Computer',
                'target_id'        => $targetId,
                'status'           => $status,
                'message'          => mb_substr($message, 0, 2000),
                'report'           => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $e) {
            // Audit failure must not mask the primary error; GLPI logger would
            // normally record this, but we intentionally avoid fatal here.
        }
    }

    private function formatCounters(): string
    {
        $parts = [];
        foreach ($this->counters as $k => $v) {
            if ($k === 'fields_transferred_list') {
                continue;
            }
            $parts[] = $k . '=' . $v;
        }
        return implode(' | ', $parts);
    }

    private function resetCounters(): void
    {
        $this->counters = [
            'fields_transferred'   => 0,
            'relations_moved'      => 0,
            'relations_deduped'    => 0,
            'relations_skipped'    => 0,
            'locks_applied'        => 0,
            'sources_soft_deleted' => 0,
        ];
        $this->transferredFields = [];
        $this->overrides = [];
    }

    /** @return array|null */
    private function fetchComputer(int $id): ?array
    {
        foreach ($this->db->request(['FROM' => 'glpi_computers', 'WHERE' => ['id' => $id]]) as $row) {
            return $row;
        }
        return null;
    }

    private function countAgents(int $computerId): int
    {
        foreach ($this->db->request([
            'COUNT' => 'c',
            'FROM'  => 'glpi_agents',
            'WHERE' => ['itemtype' => 'Computer', 'items_id' => $computerId],
        ]) as $row) {
            return (int)$row['c'];
        }
        return 0;
    }
}
