<?php
/**
 * AssetMerge — read-only preview engine.
 *
 * IMPORTANT: this class performs SELECT queries only. No UPDATE, INSERT,
 * DELETE, or DDL is executed anywhere in P1+P2. The merge execution engine
 * belongs to a later phase and is intentionally absent from this release.
 */

namespace GlpiPlugin\AssetMerge;

use DBmysql;
use Session;

final class PreviewEngine
{
    /** @var DBmysql */
    private $db;

    public function __construct(DBmysql $db)
    {
        $this->db = $db;
    }

    /**
     * Build a read-only preview report.
     *
     * @param int   $targetId  record to keep (must be dynamic + agent-bound)
     * @param int[] $sourceIds records that would be soft-deleted in a future merge
     * @return array{ok:bool,errors:array,warnings:array,target:array|null,sources:array,fields:array,relations:array,locks:array,summary:array}
     */
    public function analyze(int $targetId, array $sourceIds): array
    {
        $report = [
            'ok'         => true,
            'errors'     => [],
            'warnings'   => [],
            'target'     => null,
            'sources'    => [],
            'fields'     => [],
            'relations'  => [],
            'locks'      => [],
            'summary'    => [],
        ];

        $sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds))));
        if ($targetId <= 0) {
            $report['errors'][] = __('No target record selected.', 'assetmerge');
            $report['ok'] = false;
            return $report;
        }
        if (count($sourceIds) === 0) {
            $report['errors'][] = __('No source records selected.', 'assetmerge');
            $report['ok'] = false;
            return $report;
        }
        if (in_array($targetId, $sourceIds, true)) {
            $report['errors'][] = __('A record cannot be both target and source.', 'assetmerge');
            $report['ok'] = false;
            return $report;
        }

        $target = $this->fetchComputer($targetId);
        if ($target === null) {
            $report['errors'][] = sprintf(__('Target computer #%d not found.', 'assetmerge'), $targetId);
            $report['ok'] = false;
            return $report;
        }

        // Gate 1: target must be dynamic (user decision: never keep a static record).
        if ((int)($target['is_dynamic'] ?? 0) !== 1) {
            $report['errors'][] = sprintf(
                __('Target #%d is not dynamic (is_dynamic=0). Keeping it would cause the agent to create a third record. Choose the agent-created record as target.', 'assetmerge'),
                $targetId
            );
            $report['ok'] = false;
        }

        // Gate 2: target must own the agent binding.
        $targetAgents = $this->countAgents($targetId);
        $targetLatest = $this->latestAgentContact($targetId) ?? '';
        if ($targetAgents === 0) {
            $report['errors'][] = sprintf(
                __('Target #%d has no agent binding. Choose the agent-created record as target.', 'assetmerge'),
                $targetId
            );
            $report['ok'] = false;
        }
        $report['target'] = [
            'id'          => $targetId,
            'name'        => $target['name'] ?? '',
            'serial'      => $target['serial'] ?? '',
            'otherserial' => $target['otherserial'] ?? '',
            'is_dynamic'  => (int)($target['is_dynamic'] ?? 0),
            'entities_id' => (int)($target['entities_id'] ?? 0),
            'agents'      => $targetAgents,
        ];

        foreach ($sourceIds as $sid) {
            $src = $this->fetchComputer($sid);
            if ($src === null) {
                $report['errors'][] = sprintf(__('Source computer #%d not found.', 'assetmerge'), $sid);
                $report['ok'] = false;
                continue;
            }
            if ((int)($src['entities_id'] ?? -1) !== (int)($target['entities_id'] ?? -2)) {
                $report['errors'][] = sprintf(
                    __('Source #%d is in a different entity than target #%d. Cross-entity merge is not allowed.', 'assetmerge'),
                    $sid,
                    $targetId
                );
                $report['ok'] = false;
            }
            $srcAgents = $this->countAgents($sid);
            if ($srcAgents > 0) {
                $srcLatest = $this->latestAgentContact($sid) ?? '';
                // Replacement-hardware / new-UUID case: if the SOURCE holds a
                // more recently active agent than the target, the operator has
                // picked the wrong direction. Merging would orphan the live
                // agent, which reports again and recreates a third record.
                if ($srcLatest !== '' && strcmp($srcLatest, $targetLatest) > 0) {
                    $report['errors'][] = sprintf(
                        __('Source #%d has a newer active agent (%s) than target #%d (%s). Likely a replacement-UUID duplicate: choose the actively reporting record as TARGET and the stale one as source, then re-run preview.', 'assetmerge'),
                        $sid,
                        $srcLatest,
                        $targetId,
                        $targetLatest !== '' ? $targetLatest : __('none', 'assetmerge')
                    );
                    $report['ok'] = false;
                } else {
                    $report['warnings'][] = sprintf(
                        __('Source #%d has %d stale agent binding(s). They will be unbound (set to no computer); target #%d keeps its single agent. Verify the source agent is no longer reporting.', 'assetmerge'),
                        $sid,
                        $srcAgents,
                        $targetId
                    );
                }
            }
            $report['sources'][] = [
                'id'          => $sid,
                'name'        => $src['name'] ?? '',
                'serial'      => $src['serial'] ?? '',
                'otherserial' => $src['otherserial'] ?? '',
                'is_dynamic'  => (int)($src['is_dynamic'] ?? 0),
                'entities_id' => (int)($src['entities_id'] ?? 0),
                'agents'      => $srcAgents,
            ];
        }

        if (!$report['ok']) {
            return $report;
        }

        $report['fields']  = $this->buildFieldDiff($target, $sourceIds);
        $report['relations'] = $this->buildRelationCounts($targetId, $sourceIds);
        $report['locks']   = RelationMap::fieldsToLock();

        $relationRows = 0;
        foreach ($report['relations'] as $rel) {
            // Only rows that will actually be moved count toward the total.
            if ($rel['status'] === 'ok') {
                $relationRows += (int)$rel['source_count'];
            }
        }
        $conflicts = 0;
        foreach ($report['fields'] as $f) {
            if ($f['conflict']) {
                $conflicts++;
            }
        }
        $report['summary'] = [
            'source_count'        => count($sourceIds),
            'relation_rows'       => $relationRows,
            'field_conflicts'     => $conflicts,
            'locks_to_apply'      => count($report['locks']),
            'manual_decision_req' => $conflicts > 0,
        ];

        return $report;
    }

    /**
     * @param int $id
     * @return array|null
     */
    private function fetchComputer(int $id): ?array
    {
        foreach ($this->db->request(['FROM' => 'glpi_computers', 'WHERE' => ['id' => $id]]) as $row) {
            return $row;
        }
        return null;
    }

    /**
     * @param int $computerId
     * @return int
     */
    private function countAgents(int $computerId): int
    {
        $it = $this->db->request([
            'COUNT'  => 'c',
            'FROM'   => 'glpi_agents',
            'WHERE'  => ['itemtype' => 'Computer', 'items_id' => $computerId],
        ]);
        foreach ($it as $row) {
            return (int)$row['c'];
        }
        return 0;
    }

    /**
     * @param int $computerId
     * @return string|null most recent last_contact of this computer's agents
     */
    private function latestAgentContact(int $computerId): ?string
    {
        $latest = null;
        foreach ($this->db->request([
            'FROM'  => 'glpi_agents',
            'WHERE' => ['itemtype' => 'Computer', 'items_id' => $computerId],
        ]) as $row) {
            $lc = $row['last_contact'] ?? null;
            if ($lc !== null && $lc !== '' && ($latest === null || strcmp((string)$lc, $latest) > 0)) {
                $latest = (string)$lc;
            }
        }
        return $latest;
    }

    /**
     * Build field-by-field diff using configured policy.
     *
     * @param array $target
     * @param int[] $sourceIds
     * @return array<string,array{field:string,target:string,source:string,winner:string,lock:bool,conflict:bool,reason:string}>
     */
    private function buildFieldDiff(array $target, array $sourceIds): array
    {
        $policies  = RelationMap::fieldPolicies();
        $sentinels = RelationMap::blankSentinels();
        $diff      = [];

        // Aggregate source values: first non-blank value wins for display.
        $sourceRows = [];
        foreach ($sourceIds as $sid) {
            $row = $this->fetchComputer($sid);
            if ($row !== null) {
                $sourceRows[] = $row;
            }
        }

        foreach ($policies as $key => $policy) {
            $field = $policy['field'];
            // groups is a relation row, not a scalar column on glpi_computers.
            if ($field === 'groups') {
                $tval  = $this->groupDisplay((int)$target['id']);
                $sval  = '';
                foreach ($sourceIds as $sid) {
                    $g = $this->groupDisplay((int)$sid);
                    if ($g !== '') { $sval = $g; break; }
                }
                $tBlank = ($tval === '');
                $diff['groups'] = [
                    'field'    => 'groups',
                    'target'   => $tval !== '' ? $tval : __('(none)', 'assetmerge'),
                    'source'   => $sval !== '' ? $sval : __('(none)', 'assetmerge'),
                    'winner'   => 'target',
                    'lock'     => false,
                    'conflict' => false,
                    'resolved' => __('Opt-in to adopt source group(s)', 'assetmerge'),
                    'reason'   => $policy['reason'],
                    'tblank'   => $tBlank,
                    'overridable'    => true,
                    'target_display' => $tval !== '' ? $tval : __('(none)', 'assetmerge'),
                    'source_display' => $sval !== '' ? $sval : __('(none)', 'assetmerge'),
                ];
                continue;
            }
            $tval  = (string)($target[$field] ?? '');
            $sval  = '';
            foreach ($sourceRows as $srow) {
                $candidate = (string)($srow[$field] ?? '');
                $blanks = $sentinels[$field] ?? [''];
                if (!in_array($candidate, $blanks, true)) {
                    $sval = $candidate;
                    break;
                }
            }

            $tBlank = in_array($tval, $sentinels[$field] ?? [''], true);
            $conflict = false;
            $winner = $policy['winner'];

            if ($winner === 'manual') {
                // Conflict when both sides hold a meaningful value that differs.
                $sBlank = in_array($sval, $sentinels[$field] ?? [''], true);
                $conflict = (!$tBlank && !$sBlank && $tval !== $sval);
                $resolved = $conflict
                    ? __('MANUAL DECISION REQUIRED', 'assetmerge')
                    : (!$tBlank ? $tval : ($sBlank ? __('none', 'assetmerge') : $sval));
            } else {
                $resolved = $tval !== '' ? $tval : ($sval !== '' ? $sval : __('none', 'assetmerge'));
            }

            $overridable = !empty($policy['overridable']);
            $diff[$key] = [
                'field'    => $field,
                'target'   => $tval !== '' ? $tval : __('(blank)', 'assetmerge'),
                'source'   => $sval !== '' ? $sval : __('(blank)', 'assetmerge'),
                'winner'   => $winner,
                'lock'     => (bool)$policy['lock'],
                'conflict' => $conflict,
                'resolved' => $resolved,
                'reason'   => $policy['reason'],
                'tblank'   => $tBlank,
                'overridable'     => $overridable,
                'target_display'  => $overridable ? $this->resolveDisplay($field, $tval) : '',
                'source_display'  => $overridable ? $this->resolveDisplay($field, $sval) : '',
            ];
        }

        return $diff;
    }

    /**
     * Human readable group membership(s) for a computer, from glpi_groups_items.
     * type 1 = owner group, type 2 = technician group.
     */
    private function groupDisplay(int $computerId): string
    {
        if ($computerId <= 0 || !$this->tableExists('glpi_groups_items')) {
            return '';
        }
        $rows = [];
        foreach ($this->db->request([
            'FROM'  => 'glpi_groups_items',
            'WHERE' => [
                'itemtype' => 'Computer',
                'items_id' => $computerId,
            ],
        ]) as $r) {
            $rows[] = $r;
        }
        $labels = [];
        foreach ($rows as $r) {
            $type = (int)($r['type'] ?? 0);
            $gid  = (int)($r['groups_id'] ?? 0);
            $name = '#' . $gid;
            if ($this->tableExists('glpi_groups')) {
                foreach ($this->db->request(['FROM' => 'glpi_groups', 'WHERE' => ['id' => $gid]]) as $g) {
                    $name = (string)($g['completename'] ?? $g['name'] ?? $name);
                    break;
                }
            }
            $labels[] = ($type === 1 ? __('Owner', 'assetmerge') : ($type === 2 ? __('Tech', 'assetmerge') : 't' . $type)) . ': ' . $name;
        }
        return implode('; ', $labels);
    }

    /**
     * Resolve a raw FK/ID to a human friendly label for the confirmation UI.
     * states_id -> glpi_states.name; users_id -> glpi_users login/real name.
     * Returns a sensible fallback when no lookup applies or fails.
     */
    private function resolveDisplay(string $field, string $value): string
    {
        if ($value === '' || $value === __('(blank)', 'assetmerge')) {
            return __('(blank)', 'assetmerge');
        }
        $id = (int)$value;
        if ($id <= 0) {
            return $value === '0' ? __('(none)', 'assetmerge') : $value;
        }
        if ($field === 'states_id') {
            foreach ($this->db->request(['FROM' => 'glpi_states', 'WHERE' => ['id' => $id]]) as $r) {
                return (string)($r['name'] ?? $value) . " (#{$id})";
            }
        } elseif ($field === 'users_id') {
            foreach ($this->db->request(['FROM' => 'glpi_users', 'WHERE' => ['id' => $id]]) as $r) {
                $label = (string)($r['name'] ?? '');
                $real  = trim(((string)($r['realname'] ?? '')) . ' ' . ((string)($r['firstname'] ?? '')));
                if ($real !== '') {
                    $label .= ($label !== '' ? ' / ' : '') . $real;
                }
                return ($label !== '' ? $label : $value) . " (#{$id})";
            }
        }
        return $value;
    }

    /**
     * Count rows per relation table for target and sources (SELECT only).
     *
     * @param int   $targetId
     * @param int[] $sourceIds
     * @return array<int,array{class:string,table:string,target_count:int,source_count:int,status:string,note:string}>
     */
    private function buildRelationCounts(int $targetId, array $sourceIds): array
    {
        $out = [];
        // Sources with their own agent are stale hardware snapshots: their
        // inventory rows must NOT be moved to the live target (see
        // RelationMap::inventoryTables()). Mark them in the report.
        $sourceHasAgent = false;
        foreach ($sourceIds as $sidProbe) {
            if ($this->countAgents($sidProbe) > 0) {
                $sourceHasAgent = true;
                break;
            }
        }
        $inventoryTables = $sourceHasAgent ? array_flip(RelationMap::inventoryTables()) : [];

        foreach (RelationMap::relations() as $rel) {
            $table = $rel['table'];
            $col   = $rel['where'];
            $isInventory = isset($inventoryTables[$table]);
            $entry = [
                'class'        => $rel['class'],
                'table'        => $table,
                'target_count' => 0,
                'source_count' => 0,
                'status'       => $isInventory ? 'skipped_inventory' : 'ok',
                'note'         => $isInventory
                    ? __('Inventory snapshot from stale source: NOT moved; target agent is authoritative.', 'assetmerge')
                    : $rel['note'],
            ];

            if (!$this->tableExists($table)) {
                $entry['status'] = 'table_missing';
                $out[] = $entry;
                continue;
            }
            if (!$this->columnExists($table, $col)) {
                $entry['status'] = 'column_missing';
                $out[] = $entry;
                continue;
            }

            $entry['target_count'] = $this->countRows($table, $col, $targetId);
            foreach ($sourceIds as $sid) {
                $entry['source_count'] += $this->countRows($table, $col, $sid);
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @param string $table
     * @return bool
     */
    private function tableExists(string $table): bool
    {
        // GLPI 12 DB::request() requires an array criteria; raw SQL strings are
        // rejected. Use the DBAL helper instead (works on GLPI 11 and 12).
        return $this->db->tableExists($table);
    }

    /**
     * @param string $table
     * @param string $column
     * @return bool
     */
    private function columnExists(string $table, string $column): bool
    {
        return $this->db->fieldExists($table, $column);
    }

    /**
     * @param string $table
     * @param string $column
     * @param int    $itemId
     * @return int
     */
    private function countRows(string $table, string $column, int $itemId): int
    {
        $where = ["{$table}.{$column}" => $itemId];
        if ($this->columnExists($table, 'itemtype')) {
            $where["{$table}.itemtype"] = 'Computer';
        }
        $it = $this->db->request([
            'COUNT' => 'c',
            'FROM'  => $table,
            'WHERE' => $where,
        ]);
        foreach ($it as $row) {
            return (int)$row['c'];
        }
        return 0;
    }
}
