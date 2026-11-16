<?php

require_once dirname(__DIR__) . '/inc/autoload.php';

if (!class_exists('GlpiPlugin\\G4freportsana\\Menu')) {
    $src = dirname(__DIR__) . '/src/Menu.php';
    if (is_file($src)) {
        require_once $src;
    }
}

class PluginG4freportsanaMenu extends \GlpiPlugin\G4freportsana\Menu
{
}
