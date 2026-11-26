<?php
/**
 * AssetMerge — read-only preview report page.
 *
 * Renders the report produced by PreviewEngine. NO merge is executed here.
 * Includes a prominent notice that execution is not implemented in P1+P2.
 */

include('../../../inc/includes.php');

// GLPI 12 loads front files via require() inside LegacyFileLoadController's
// method scope, so superglobal-style GLPI globals are NOT auto-visible here.
global $DB, $CFG_GLPI;

Session::checkRight('computer', READ);

Html::header(__('Asset merge — preview (read-only)', 'assetmerge'), $_SERVER['PHP_SELF'], 'assets', 'Computer');

$report = $_SESSION['assetmerge_preview'] ?? null;

echo "<div class='card'>";
echo "<div class='card-header'><h3>" . __('Asset merge — preview report', 'assetmerge') . "</h3></div>";
echo "<div class='card-body'>";

echo "<div class='alert alert-warning' role='alert'>";
echo "<strong>" . __('READ-ONLY PREVIEW', 'assetmerge') . "</strong> — " .
    __('This release does not execute merges. No GLPI data was modified. The report below only shows what a future merge would do.', 'assetmerge');
echo "</div>";

if ($report === null) {
    echo "<p class='alert alert-info'>" .
        __('No preview available. Select computers in the computer list and choose the “Asset merge — preview (read-only)” massive action.', 'assetmerge') .
        "</p>";
} else {
    echo "<p class='text-muted'>" .
        sprintf(__('Generated at %s by %s', 'assetmerge'),
            htmlspecialchars((string)($report['generated_at'] ?? '')),
            htmlspecialchars((string)($report['requested_by'] ?? ''))) .
        "</p>";

    if (!empty($report['errors'])) {
        echo "<div class='alert alert-danger'><ul>";
        foreach ($report['errors'] as $err) {
            echo "<li>" . htmlspecialchars((string)$err) . "</li>";
        }
        echo "</ul></div>";
    }
    if (!empty($report['warnings'])) {
        echo "<div class='alert alert-warning'><ul>";
        foreach ($report['warnings'] as $warn) {
            echo "<li>" . htmlspecialchars((string)$warn) . "</li>";
        }
        echo "</ul></div>";
    }

    // Summary
    if (!empty($report['summary'])) {
        echo "<h4>" . __('Summary', 'assetmerge') . "</h4>";
        echo "<table class='table table-sm table-bordered'><tbody>";
        foreach ([
            'source_count'        => __('Source records to soft-delete', 'assetmerge'),
            'relation_rows'       => __('Relation rows to move', 'assetmerge'),
            'field_conflicts'     => __('Field conflicts needing manual decision', 'assetmerge'),
            'locks_to_apply'      => __('Manual fields to lock after merge', 'assetmerge'),
            'manual_decision_req' => __('Manual decision required', 'assetmerge'),
        ] as $key => $label) {
            $val = $report['summary'][$key] ?? 0;
            echo "<tr><th>" . htmlspecialchars($label) . "</th><td>" .
                (is_bool($val) ? ($val ? __('Yes', 'assetmerge') : __('No', 'assetmerge')) : (int)$val) .
                "</td></tr>";
        }
        echo "</tbody></table>";
    }

    // Target and sources
    echo "<h4>" . __('Records', 'assetmerge') . "</h4>";
    echo "<table class='table table-sm table-hover'><thead><tr><th>#</th><th>" . __('Role', 'assetmerge') .
        "</th><th>" . __('Name', 'assetmerge') . "</th><th>" . __('Serial', 'assetmerge') .
        "</th><th>" . __('Asset number', 'assetmerge') . "</th><th>" . __('Dynamic', 'assetmerge') .
        "</th><th>" . __('Agents', 'assetmerge') . "</th></tr></thead><tbody>";
    if (!empty($report['target'])) {
        $t = $report['target'];
        echo "<tr class='table-success'><td>" . (int)$t['id'] . "</td><td>" . __('KEEP (target)', 'assetmerge') .
            "</td><td>" . htmlspecialchars((string)$t['name']) . "</td><td>" . htmlspecialchars((string)$t['serial']) .
            "</td><td>" . htmlspecialchars((string)$t['otherserial']) . "</td><td>" . (int)$t['is_dynamic'] .
            "</td><td>" . (int)$t['agents'] . "</td></tr>";
    }
    foreach ($report['sources'] ?? [] as $s) {
        echo "<tr class='table-danger'><td>" . (int)$s['id'] . "</td><td>" . __('SOFT-DELETE (source)', 'assetmerge') .
            "</td><td>" . htmlspecialchars((string)$s['name']) . "</td><td>" . htmlspecialchars((string)$s['serial']) .
            "</td><td>" . htmlspecialchars((string)$s['otherserial']) . "</td><td>" . (int)$s['is_dynamic'] .
            "</td><td>" . (int)$s['agents'] . "</td></tr>";
    }
    echo "</tbody></table>";

    // Field diff
    if (!empty($report['fields'])) {
        echo "<h4>" . __('Field conflicts and proposed resolution', 'assetmerge') . "</h4>";
        echo "<table class='table table-sm table-bordered'><thead><tr><th>" . __('Field', 'assetmerge') .
            "</th><th>" . __('Target value', 'assetmerge') . "</th><th>" . __('Source value', 'assetmerge') .
            "</th><th>" . __('Policy', 'assetmerge') . "</th><th>" . __('Proposed resolution', 'assetmerge') .
            "</th><th>" . __('Lock', 'assetmerge') . "</th><th>" . __('Reason', 'assetmerge') . "</th></tr></thead><tbody>";
        foreach ($report['fields'] as $f) {
            $cls = $f['conflict'] ? 'table-warning' : '';
            echo "<tr class='{$cls}'>";
            echo "<td>" . htmlspecialchars((string)$f['field']) . "</td>";
            echo "<td>" . htmlspecialchars((string)$f['target']) . "</td>";
            echo "<td>" . htmlspecialchars((string)$f['source']) . "</td>";
            echo "<td>" . htmlspecialchars((string)$f['winner']) . "</td>";
            echo "<td>" . htmlspecialchars((string)($f['resolved'] ?? '')) . "</td>";
            echo "<td>" . ($f['lock'] ? __('Yes', 'assetmerge') : __('No', 'assetmerge')) . "</td>";
            echo "<td>" . htmlspecialchars((string)$f['reason']) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
        echo "<p class='alert alert-warning'>" .
            __('Fields marked “MANUAL DECISION REQUIRED” must be adjudicated by an operator before any future merge is executed.', 'assetmerge') .
            "</p>";
    }

    // Relations
    if (!empty($report['relations'])) {
        echo "<h4>" . __('Relations to move', 'assetmerge') . "</h4>";
        echo "<table class='table table-sm table-hover'><thead><tr><th>" . __('Class', 'assetmerge') .
            "</th><th>" . __('Table', 'assetmerge') . "</th><th>" . __('Target rows', 'assetmerge') .
            "</th><th>" . __('Source rows', 'assetmerge') . "</th><th>" . __('Status', 'assetmerge') .
            "</th><th>" . __('Note', 'assetmerge') . "</th></tr></thead><tbody>";
        foreach ($report['relations'] as $r) {
            $cls = $r['status'] !== 'ok' ? 'table-secondary' : '';
            echo "<tr class='{$cls}'>";
            echo "<td>" . htmlspecialchars((string)$r['class']) . "</td>";
            echo "<td>" . htmlspecialchars((string)$r['table']) . "</td>";
            echo "<td>" . (int)$r['target_count'] . "</td>";
            echo "<td>" . (int)$r['source_count'] . "</td>";
            echo "<td>" . htmlspecialchars((string)$r['status']) . "</td>";
            echo "<td>" . htmlspecialchars((string)$r['note']) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    }

    // Field locks
    if (!empty($report['locks'])) {
        echo "<h4>" . __('Manual fields to lock after a future merge', 'assetmerge') . "</h4>";
        echo "<p>" . htmlspecialchars(implode(', ', $report['locks'])) . "</p>";
        echo "<p class='text-muted'>" .
            __('Locking prevents the next agent inventory from overwriting manually transferred values (e.g. fixed asset number).', 'assetmerge') .
            "</p>";
    }

    // P3 execution entry: only when the preview is OK and no manual decision
    // is pending. Requires UPDATE+DELETE rights.
    if (!empty($report['ok'])
        && empty($report['summary']['manual_decision_req'])
        && Session::haveRight('computer', UPDATE)
        && Session::haveRight('computer', DELETE)
        && !empty($report['target']['id'])) {
        $targetId  = (int)$report['target']['id'];
        $sourceIds = array_map(static function ($s) { return (int)$s['id']; }, $report['sources'] ?? []);
        $token     = Session::getNewCSRFToken();
        echo "<hr>";
        echo "<h4>" . __('Execute merge', 'assetmerge') . "</h4>";
        echo "<div class='alert alert-danger' role='alert'>" .
            "<strong>" . __('DESTRUCTIVE ACTION', 'assetmerge') . "</strong> — " .
            __('The source records will be moved to the trash and their relations transferred. This is executed inside a transaction and is fully audited.', 'assetmerge') .
            "</div>";
        echo "<form method='post' action='" . htmlspecialchars($CFG_GLPI['root_doc']) . "/plugins/assetmerge/front/merge.execute.php'>";
        echo "<input type='hidden' name='_glpi_csrf_token' value='" . htmlspecialchars($token) . "'>";
        echo "<input type='hidden' name='target_id' value='{$targetId}'>";
        echo "<input type='hidden' name='source_ids' value='" . htmlspecialchars(implode(',', $sourceIds)) . "'>";
        echo "<label><input type='checkbox' name='confirm' value='1' required> " .
            htmlspecialchars(__('I have reviewed the preview and confirm the merge.', 'assetmerge')) . "</label><br><br>";
        // IMPORTANT: do NOT send 'execute' here. merge.execute.php treats any
        // POST with execute=1 as the final execution and skips the confirmation
        // screen — which would hide the optional override checkboxes. Use a
        // distinct button name so we land on the GET-style confirmation page
        // first, where the operator can tick status / user / group overrides.
        echo "<button type='submit' class='btn btn-danger' name='confirm_step' value='1'>" .
            htmlspecialchars(__('Review and execute merge', 'assetmerge')) . "</button>";
        echo "</form>";
    }
}

echo "</div></div>";
Html::footer();
