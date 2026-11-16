<?php

namespace GlpiPlugin\G4freportsana\Ana;

/** G4F brand manual, page 33. Tints below are derived for readable report cells. */
final class ReportStyle
{
    public const NAVY = '011224';
    public const BLUE = '376BFF';
    public const BLUE_DARK = '3160E6';
    public const YELLOW = 'FFE554';
    public const YELLOW_DARK = 'B3A03B';
    public const LIGHT_GRAY = 'DAE6F2';
    public const GRAY = '939BA3';
    public const BORDER = 'C1CCD6';
    public const WHITE = 'FFFFFF';
    public const MUTED = '033870';

    // Derived tints: navy labels remain readable throughout the heatmap scale.
    public const HEATMAP_MAX = '9BB5FF';
    public const POSITIVE_FILL = 'E7EDFF';
    public const CAUTION_FILL = 'FFF2A9';

    public static function heatmapColor(float $ratio): string
    {
        $ratio = max(0.0, min(1.0, $ratio));
        $color = '';
        for ($channel = 0; $channel < 3; $channel++) {
            $high = hexdec(substr(self::HEATMAP_MAX, $channel * 2, 2));
            $color .= sprintf('%02X', (int)round(255 + ($high - 255) * $ratio));
        }
        return $color;
    }
}
