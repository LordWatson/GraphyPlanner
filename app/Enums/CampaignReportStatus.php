<?php

namespace App\Enums;

/**
 * Step C-7.3 — a wrap report is a draft until it's shared with the client, which freezes its KPI
 * snapshot.
 */
enum CampaignReportStatus: string
{
    case Draft = 'draft';
    case Shared = 'shared';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Shared => 'Shared',
        };
    }
}
