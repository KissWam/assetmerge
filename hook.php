<?php
/**
 * AssetMerge hook handlers.
 * P1+P2: install/uninstall schema; expose read-only preview massive action.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly.");
}

/**
 * Declare plugin massive actions.
 *
 * Per GLPI 12 MassiveAction.php, the action key must be 'Class:method'
 * (single colon), where Class is the processor and 'method' becomes the
 * action name read by $ma->getAction().
 *
 * @param string $itemtype
 * @return array
 */
function plugin_assetmerge_MassiveActions($itemtype)
{
    if ($itemtype !== 'Computer') {
        return [];
    }

    if (!Session::haveRight('computer', UPDATE) || !Session::haveRight('computer', DELETE)) {
        return [];
    }

    return [
        'GlpiPlugin\\AssetMerge\\PreviewProcessor:preview' => __('Asset merge — preview (read-only)', 'assetmerge'),
    ];
}

/**
 * Install plugin schema.
 *
 * Creates:
 *  - glpi_plugin_assetmerge_logs     audit trail, 1-year retention
 *  - glpi_plugin_assetmerge_locks    merge operation concurrency guards
 *
 * @return bool
 */
function plugin_assetmerge_install()
{
    global $DB;

    if (!$DB->tableExists('glpi_plugin_assetmerge_logs')) {
        $sql = "CREATE TABLE `glpi_plugin_assetmerge_logs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `action` varchar(32) NOT NULL DEFAULT 'preview',
            `source_itemtype` varchar(100) NOT NULL DEFAULT 'Computer',
            `source_id` int unsigned NOT NULL DEFAULT 0,
            `target_itemtype` varchar(100) NOT NULL DEFAULT 'Computer',
            `target_id` int unsigned NOT NULL DEFAULT 0,
            `status` varchar(20) NOT NULL DEFAULT 'preview',
            `message` text DEFAULT NULL,
            `report` longtext DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `date_creation` (`date_creation`),
            KEY `source_idx` (`source_itemtype`, `source_id`),
            KEY `target_idx` (`target_itemtype`, `target_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $DB->doQuery($sql) or die('AssetMerge install failed: ' . $DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_assetmerge_locks')) {
        $sql = "CREATE TABLE `glpi_plugin_assetmerge_locks` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `computer_id` int unsigned NOT NULL,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `session_id` varchar(128) DEFAULT NULL,
            `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `computer_id` (`computer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $DB->doQuery($sql) or die('AssetMerge install failed: ' . $DB->error());
    }

    // Config: audit log retention = 365 days (user decision 2026-11-25).
    $cfg = Config::getConfigurationValues('plugin:assetmerge');
    if (!isset($cfg['audit_retention_days'])) {
        Config::setConfigurationValues('plugin:assetmerge', [
            'audit_retention_days' => 365,
            'require_manual_conflict_decision' => 1,
            'lock_transferred_manual_fields' => 1,
        ]);
    }

    return true;
}

/**
 * Uninstall plugin schema.
 * Audit logs are 1-year retained by design; uninstall purges them only on
 * explicit operator action. P1+P2 does not auto-delete audit data.
 *
 * @return bool
 */
function plugin_assetmerge_uninstall()
{
    global $DB;

    $DB->doQuery("DROP TABLE IF EXISTS `glpi_plugin_assetmerge_locks`");
    // Keep logs table: audit retention policy requires 1-year history.
    Config::deleteConfigurationValues('plugin:assetmerge', [
        'audit_retention_days',
        'require_manual_conflict_decision',
        'lock_transferred_manual_fields',
    ]);

    return true;
}
