<?php
/**
 * AssetMerge — GLPI 12 asset merge plugin (non-official)
 *
 * P1+P2 scope: plugin skeleton + read-only preview engine.
 * NO production write operations are implemented in this version.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly.");
}

use Glpi\Plugin\Hooks;

define('PLUGIN_ASSETMERGE_VERSION', '0.2.0');

/**
 * Autoload plugin classes under GlpiPlugin\AssetMerge namespace.
 * GLPI 12 registers plugin namespaces automatically, but an explicit
 * fallback keeps the plugin working on 12.0.x without composer dump.
 */
spl_autoload_register(function ($class) {
    $prefix = 'GlpiPlugin\\AssetMerge\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

function plugin_version_assetmerge()
{
    return [
        'name'           => 'Asset Merge',
        'version'        => PLUGIN_ASSETMERGE_VERSION,
        'author'         => 'KISSWAM',
        'license'        => 'GPLv2+',
        'homepage'       => 'https://github.com/KissWam/glpi-manager',
        'requirements'   => [
            'glpi' => [
                'min' => '12.0',
                'max' => '12.0.99',
            ],
        ],
    ];
}

function plugin_assetmerge_check_prerequisites()
{
    if (version_compare(GLPI_VERSION, '12.0', '<')) {
        echo 'AssetMerge requires GLPI >= 12.0';
        return false;
    }
    return true;
}

function plugin_init_assetmerge()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['assetmerge'] = true;

    // Declare that this plugin provides massive actions.
    // The action itself is exposed by plugin_assetmerge_MassiveActions() in hook.php.
    $PLUGIN_HOOKS[Hooks::USE_MASSIVE_ACTION]['assetmerge'] = true;

    $PLUGIN_HOOKS['config_page']['assetmerge'] = 'front/config.php';
}

function plugin_assetmerge_check_config($verbose = false)
{
    return true;
}
