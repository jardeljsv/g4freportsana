<?php

namespace GlpiPlugin\G4freportsana;

class Menu extends \CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Relatórios G4F — ANA', 'g4freportsana');
    }

    public static function getMenuName()
    {
        return self::getTypeName();
    }

    public static function getMenuContent(): array
    {
        global $CFG_GLPI;

        $base = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/g4freportsana/front';

        return [
            'title' => self::getMenuName(),
            'page'  => $base . '/ana.php',
            'icon'  => 'ti ti-file-export'
        ];
    }
}
