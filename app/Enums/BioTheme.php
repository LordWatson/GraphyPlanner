<?php

namespace App\Enums;

/**
 * Step CG-3.2 — the bio page's look. Presets only: each case names a brand token from the design
 * system (`--color-cream`, `--color-lime`, …), never a raw hex, so a creator can't drop the page
 * out of the palette or break its contrast.
 */
enum BioTheme: string
{
    case Cream = 'cream';
    case Ink = 'ink';
    case Lime = 'lime';
    case Electric = 'electric';
    case Lilac = 'lilac';

    public function label(): string
    {
        return match ($this) {
            self::Cream => 'Cream',
            self::Ink => 'Ink',
            self::Lime => 'Lime',
            self::Electric => 'Electric',
            self::Lilac => 'Lilac',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $theme) => $theme->value, self::cases());
    }
}
