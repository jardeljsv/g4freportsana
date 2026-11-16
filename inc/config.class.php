<?php

require_once dirname(__DIR__) . '/inc/autoload.php';

if (!class_exists('GlpiPlugin\\G4freportsana\\Config')) {
    $src = dirname(__DIR__) . '/src/Config.php';
    if (is_file($src)) {
        require_once $src;
    }
}

class PluginG4freportsanaConfig extends \GlpiPlugin\G4freportsana\Config
{
}
