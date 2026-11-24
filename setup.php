<?php
// plugins/g4freportsana/setup.php

// GLPI reads this file for discovery before initializing the plugin. Keep
// metadata independent of the host runtime, class inheritance and autoloaders.
if (!defined('PLUGIN_G4FREPORTSANA_VERSION')) { define('PLUGIN_G4FREPORTSANA_VERSION', '2.0.7'); }
if (!defined('PLUGIN_G4FREPORTSANA_MIN_GLPI')) { define('PLUGIN_G4FREPORTSANA_MIN_GLPI', '11.0.0'); }
if (!defined('PLUGIN_G4FREPORTSANA_MAX_GLPI')) { define('PLUGIN_G4FREPORTSANA_MAX_GLPI', '13.0.0'); }

function plugin_init_g4freportsana(): void
{
    global $PLUGIN_HOOKS;

    // Runtime dependencies belong to the initialization phase. In GLPI 12,
    // Plugin::load has registered the native plugin loader before this call.
    require_once __DIR__ . '/inc/autoload.php';
    require_once __DIR__ . '/inc/common.php';
    require_once __DIR__ . '/inc/menu.class.php';
    require_once __DIR__ . '/inc/config.class.php';

    $PLUGIN_HOOKS['csrf_compliant']['g4freportsana'] = true;
    $PLUGIN_HOOKS['secured_configs']['g4freportsana'] = \GlpiPlugin\G4freportsana\Database\ConnectionConfig::securedConfigs();

    if (class_exists('Plugin')) {
        Plugin::registerClass(\GlpiPlugin\G4freportsana\Config::class, [
            'addtabon' => \Config::class
        ]);
        Plugin::registerClass('PluginG4freportsanaMenu');
    }

    // Left-menu entry under Tools. The menu points only to the report generator;
    // administrative configuration is exposed through Setup > General.
    $PLUGIN_HOOKS['menu_toadd']['g4freportsana'] = [
        'tools' => 'PluginG4freportsanaMenu'
    ];
}

function plugin_version_g4freportsana(): array
{
    return [
        'name'           => 'Relatórios G4F — ANA',
        'version'        => PLUGIN_G4FREPORTSANA_VERSION,
        'author'         => 'G4F',
        'license'        => 'MIT',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_G4FREPORTSANA_MIN_GLPI,
                'max' => PLUGIN_G4FREPORTSANA_MAX_GLPI
            ]
        ]
    ];
}

function plugin_g4freportsana_check_prerequisites(): bool
{
    if (defined('GLPI_VERSION') && (version_compare(GLPI_VERSION, PLUGIN_G4FREPORTSANA_MIN_GLPI, '<') || version_compare(GLPI_VERSION, PLUGIN_G4FREPORTSANA_MAX_GLPI, '>='))) {
        if (method_exists('Plugin', 'messageIncompatible')) {
            Plugin::messageIncompatible('core', PLUGIN_G4FREPORTSANA_MIN_GLPI, PLUGIN_G4FREPORTSANA_MAX_GLPI);
        }
        return false;
    }

    // No PHPWord, ZipArchive, cURL or composer-installed package is required.
    return true;
}

function plugin_g4freportsana_check_config($verbose = false): bool
{
    return true;
}
