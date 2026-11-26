<?php
/**
 * AssetMerge — P3 merge execution page.
 *
 * GET  -> confirmation screen (shows target / source / what will happen)
 * POST -> executes the transactional merge via Merger::execute()
 *
 * Requires computer UPDATE + DELETE rights and a valid GLPI CSRF token.
 * Never touches production unless explicitly deployed there.
 */

include('../../../inc/includes.php');

// GLPI 12 loads front files via require() inside LegacyFileLoadController's
// method scope; $DB / $CFG_GLPI are globals and must be imported explicitly.
global $DB, $CFG_GLPI;

Session::checkRight('computer', UPDATE);

// Both UPDATE and DELETE are required (matching the preview action gate).
if (!Session::haveRight('computer', UPDATE) || !Session::haveRight('computer', DELETE)) {
    Session::addMessageAfterRedirect(__('You do not have the rights required to merge assets.', 'assetmerge'), false, ERROR);
    Html::redirect($CFG_GLPI['root_doc'] . '/front/computer.php');
}

// Parse target + sources from POST (confirm step) with strict validation.
$targetId  = isset($_POST['target_id']) ? (int)$_POST['target_id'] : 0;
$sourceIds = [];
if (!empty($_POST['source_ids'])) {
    foreach (explode(',', (string)$_POST['source_ids']) as $part) {
        $id = (int)trim($part);
        if ($id > 0) {
            $sourceIds[] = $id;
        }
    }
}
$sourceIds = array_values(array_unique($sourceIds));
$isPost    = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute']));

Html::header(__('Asset merge — execute', 'assetmerge'), $_SERVER['PHP_SELF'], 'assets', 'Computer');

echo "<div class='card'>";
echo "<div class='card-header'><h3>" . __('Asset merge — execute (P3)', 'assetmerge') . "</h3></div>";
echo "<div class='card-body'>";

if ($targetId <= 0 || count($sourceIds) === 0 || in_array($targetId, $sourceIds, true)) {
    echo "<div class='alert alert-danger'>" .
        htmlspecialchars(__('Invalid target or source selection.', 'assetmerge')) . "</div>";
    echo "<a class='btn btn-secondary' href='" . htmlspecialchars($CFG_GLPI['root_doc']) . "/front/computer.php'>" .
        htmlspecialchars(__('Back to computers', 'assetmerge')) . "</a>";
    echo "</div></div>";
    Html::footer();
    exit;
}

// Rebuild the preview from LIVE data (never trust a stale session report).
$preview = new \GlpiPlugin\AssetMerge\PreviewEngine($DB);
$report  = $preview->analyze($targetId, $sourceIds);

if (empty($report['ok'])) {
    echo "<div class='alert alert-danger'><ul>";
    foreach ($report['errors'] as $err) {
        echo "<li>" . htmlspecialchars((string)$err) . "</li>";
    }
    echo "</ul></div>";
    echo "<p>" . htmlspecialchars(__('Merge aborted. No data was modified.', 'assetmerge')) . "</p>";
    echo "</div></div>";
    Html::footer();
    exit;
}

if (!$isPost) {
    // -------- Confirmation screen --------
    // Open the form BEFORE any content: the optional override checkboxes below
    // must live inside this form, otherwise the browser never submits
    // overrides[] and the merge silently applies zero overrides.
    echo "<form method='post' action='" . htmlspecialchars($CFG_GLPI['root_doc']) . "/plugins/assetmerge/front/merge.execute.php'>";
    echo "<input type='hidden' name='_glpi_csrf_token' value='" . htmlspecialchars(Session::getNewCSRFToken()) . "'>";
    echo "<input type='hidden' name='target_id' value='{$targetId}'>";
    echo "<input type='hidden' name='source_ids' value='" . htmlspecialchars(implode(',', $sourceIds)) . "'>";

    echo "<div class='alert alert-warning'><strong>" . __('CONFIRMATION REQUIRED', 'assetmerge') . "</strong> — " .
        htmlspecialchars(__('Review the plan below. Execution moves relations, transfers fields, applies locks and soft-deletes the source records.', 'assetmerge')) . "</div>";

    echo "<h4>" . __('Records', 'assetmerge') . "</h4>";
    echo "<table class='table table-sm table-bordered'><thead><tr><th>" . __('Role', 'assetmerge') .
        "</th><th>#</th><th>" . __('Name', 'assetmerge') . "</th><th>" . __('Serial', 'assetmerge') .
        "</th><th>" . __('Asset number', 'assetmerge') . "</th><th>" . __('Dynamic', 'assetmerge') .
        "</th></tr></thead><tbody>";
    $t = $report['target'];
    echo "<tr class='table-success'><td>" . htmlspecialchars(__('KEEP (target)', 'assetmerge')) . "</td><td>{$t['id']}</td><td>" .
        htmlspecialchars((string)$t['name']) . "</td><td>" . htmlspecialchars((string)$t['serial']) . "</td><td>" .
        htmlspecialchars((string)$t['otherserial']) . "</td><td>" . (int)$t['is_dynamic'] . "</td></tr>";
    foreach ($report['sources'] as $s) {
        echo "<tr class='table-danger'><td>" . htmlspecialchars(__('SOFT-DELETE (source)', 'assetmerge')) . "</td><td>{$s['id']}</td><td>" .
            htmlspecialchars((string)$s['name']) . "</td><td>" . htmlspecialchars((string)$s['serial']) . "</td><td>" .
            htmlspecialchars((string)$s['otherserial']) . "</td><td>" . (int)$s['is_dynamic'] . "</td></tr>";
    }
    echo "</tbody></table>";

    echo "<h4>" . __('Field transfer plan', 'assetmerge') . "</h4>";
    echo "<table class='table table-sm table-bordered'><thead><tr><th>" . __('Field', 'assetmerge') .
        "</th><th>" . __('Target value', 'assetmerge') . "</th><th>" . __('Source value', 'assetmerge') .
        "</th><th>" . __('Proposed resolution', 'assetmerge') . "</th><th>" . __('Lock', 'assetmerge') .
        "</th></tr></thead><tbody>";
    foreach ($report['fields'] as $f) {
        echo "<tr><td>" . htmlspecialchars((string)$f['field']) . "</td><td>" . htmlspecialchars((string)$f['target']) .
            "</td><td>" . htmlspecialchars((string)$f['source']) . "</td><td>" . htmlspecialchars((string)$f['resolved']) .
            "</td><td>" . ($f['lock'] ? htmlspecialchars(__('Yes', 'assetmerge')) : htmlspecialchars(__('No', 'assetmerge'))) . "</td></tr>";
    }
    echo "</tbody></table>";

    // Optional overrides: operator may elect to adopt source values for
    // states_id / users_id / group membership (default = keep target).
    $overridable = \GlpiPlugin\AssetMerge\RelationMap::overridableFields();
    $ovFields    = [];
    foreach ($overridable as $key => $meta) {
        if (isset($report['fields'][$key])) {
            $ovFields[$key] = $report['fields'][$key];
        }
    }
    if (!empty($ovFields)) {
        echo "<h4>" . __('Optional overrides (operator choice)', 'assetmerge') . "</h4>";
        echo "<div class='alert alert-info' role='alert'>" .
            htmlspecialchars(__('By default the target (agent) values are kept. Tick a box to replace them with the source record values. Ticked scalar fields are locked against future inventory updates.', 'assetmerge')) .
            "</div>";
        echo "<table class='table table-sm table-bordered'><thead><tr><th>" . __('Select', 'assetmerge') .
            "</th><th>" . __('Field', 'assetmerge') . "</th><th>" . __('Keep — target', 'assetmerge') .
            "</th><th>" . __('Override with — source', 'assetmerge') . "</th><th>" . __('Lock after override', 'assetmerge') .
            "</th></tr></thead><tbody>";
        foreach ($ovFields as $key => $f) {
            $tdisplay = htmlspecialchars((string)($f['target_display'] ?? $f['target'] ?? ''));
            $sdisplay = htmlspecialchars((string)($f['source_display'] ?? $f['source'] ?? ''));
            $lockFlag = !empty($overridable[$key]['override_lock']);
            echo "<tr>";
            echo "<td><input type='checkbox' id='ov_" . htmlspecialchars($key) . "' name='overrides[]' value='" . htmlspecialchars($key) . "'></td>";
            echo "<td><label for='ov_" . htmlspecialchars($key) . "'>" . htmlspecialchars($overridable[$key]['label']) . "</label></td>";
            echo "<td>{$tdisplay}</td>";
            echo "<td>{$sdisplay}</td>";
            echo "<td>" . ($lockFlag ? htmlspecialchars(__('Yes', 'assetmerge')) : htmlspecialchars(__('N/A (relation)', 'assetmerge'))) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    }

    $sum = $report['summary'];
    echo "<h4>" . __('Summary', 'assetmerge') . "</h4>";
    echo "<ul>";
    echo "<li>" . htmlspecialchars(__('Source records to soft-delete', 'assetmerge')) . ": " . (int)$sum['source_count'] . "</li>";
    echo "<li>" . htmlspecialchars(__('Relation rows to move', 'assetmerge')) . ": " . (int)$sum['relation_rows'] . "</li>";
    echo "<li>" . htmlspecialchars(__('Manual fields to lock', 'assetmerge')) . ": " . (int)$sum['locks_to_apply'] . "</li>";
    echo "</ul>";

    // The <form> (with CSRF + target/source hidden fields) was opened at the
    // top of the confirmation block so the override checkboxes are inside it.
    echo "<label><input type='checkbox' name='confirm' value='1' required> " .
        htmlspecialchars(__('I confirm execution of this merge.', 'assetmerge')) . "</label><br><br>";
    echo "<button type='submit' class='btn btn-danger' name='execute' value='1'>" .
        htmlspecialchars(__('Execute Merge', 'assetmerge')) . "</button> ";
    echo "<a class='btn btn-secondary' href='" . htmlspecialchars($CFG_GLPI['root_doc']) . "/front/computer.php'>" .
        htmlspecialchars(__('Cancel', 'assetmerge')) . "</a>";
    echo "</form>";
} else {
    // -------- Execute --------
    Session::checkCSRF($_POST);
    if (empty($_POST['confirm'])) {
        echo "<div class='alert alert-danger'>" . htmlspecialchars(__('Confirmation checkbox not checked.', 'assetmerge')) . "</div>";
    } else {
        // Optional overrides from the confirmation checkboxes. Values are
        // re-validated against the whitelist inside Merger::execute().
        $overrides = [];
        if (!empty($_POST['overrides']) && is_array($_POST['overrides'])) {
            foreach ($_POST['overrides'] as $ov) {
                $overrides[] = (string)$ov;
            }
        }
        $merger = new \GlpiPlugin\AssetMerge\Merger($DB);
        $res    = $merger->execute($targetId, $sourceIds, $report, $overrides);

        if (!empty($res['warnings'])) {
            echo "<div class='alert alert-warning'><ul>";
            foreach ($res['warnings'] as $w) {
                echo "<li>" . htmlspecialchars((string)$w) . "</li>";
            }
            echo "</ul></div>";
        }

        if ($res['ok']) {
            echo "<div class='alert alert-success'><strong>" . htmlspecialchars(__('Merge completed successfully.', 'assetmerge')) . "</strong></div>";
            echo "<h4>" . __('Execution statistics', 'assetmerge') . "</h4>";
            echo "<table class='table table-sm table-bordered'><tbody>";
            foreach ($res['stats'] as $k => $v) {
                echo "<tr><th>" . htmlspecialchars((string)$k) . "</th><td>" . htmlspecialchars((string)$v) . "</td></tr>";
            }
            echo "</tbody></table>";
        } else {
            echo "<div class='alert alert-danger'><strong>" . htmlspecialchars(__('Merge failed (rolled back).', 'assetmerge')) . "</strong><ul>";
            foreach ($res['errors'] as $err) {
                echo "<li>" . htmlspecialchars((string)$err) . "</li>";
            }
            echo "</ul></div>";
        }
        echo "<a class='btn btn-primary' href='" . htmlspecialchars($CFG_GLPI['root_doc']) . "/front/computer.php'>" .
            htmlspecialchars(__('Back to computers', 'assetmerge')) . "</a>";
    }
}

echo "</div></div>";
Html::footer();
