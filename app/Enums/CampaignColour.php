<?php

namespace App\Enums;

/**
 * Step C-0.2 — a campaign's identity colour on chips, calendar bands and phases. Stored as a brand
 * token name, never a hex value, so the frontend maps it to its own semantic classes and dark mode
 * keeps working. Signal Orange is deliberately absent: it is decorative only (brand §1.4).
 */
enum CampaignColour: string
{
    case Teal = 'teal';
    case Electric = 'electric';
    case Lilac = 'lilac';
    case Lime = 'lime';
    case Ink = 'ink';

    public function label(): string
    {
        return match ($this) {
            self::Teal => 'Deep teal',
            self::Electric => 'Electric blue',
            self::Lilac => 'Soft lilac',
            self::Lime => 'Acid lime',
            self::Ink => 'Midnight ink',
        };
    }
}
