<?php
/**
 * AssetMerge configuration page.
 * P1+P2: displays merge policy settings (read-only in this release).
 */

include('../../../inc/includes.php');

Session::checkRight('config', READ);

Html::header(__('Asset Merge', 'assetmerge'), $_SERVER['PHP_SELF'], 'config', 'assetmerge');

echo "<div class='card'>";
echo "<div class='card-header'><h3>" . __('Asset Merge — configuration', 'assetmerge') . "</h3></div>";
echo "<div class='card-body'>";
echo "<p class='alert alert-info'>";
echo __('Current release is P1+P2: plugin skeleton and read-only preview engine. No merge execution is implemented.', 'assetmerge');
echo "</p>";

$cfg = Config::getConfigurationValues('plugin:assetmerge');

echo "<table class='table table-hover'>";
echo "<thead><tr><th>" . __('Setting', 'assetmerge') . "</th><th>" . __('Value', 'assetmerge') . "</th><th>" . __('Description', 'assetmerge') . "</th></tr></thead><tbody>";
echo "<tr><td>audit_retention_days</td><td>" . (int)($cfg['audit_retention_days'] ?? 365) . "</td><td>" . __('Audit log retention in days', 'assetmerge') . "</td></tr>";
echo "<tr><td>require_manual_conflict_decision</td><td>" . (int)($cfg['require_manual_conflict_decision'] ?? 1) . "</td><td>" . __('Field conflicts require manual decision', 'assetmerge') . "</td></tr>";
echo "<tr><td>lock_transferred_manual_fields</td><td>" . (int)($cfg['lock_transferred_manual_fields'] ?? 1) . "</td><td>" . __('Lock manually transferred fields against inventory overwrite', 'assetmerge') . "</td></tr>";
echo "</tbody></table>";
echo "</div></div>";

Html::footer();
