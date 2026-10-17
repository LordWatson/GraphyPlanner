<?php

namespace App\Enums;

/**
 * "Draft cheap, finalise once" (Phase 16): sets render at the fast/cheap draft tier, and only the
 * variant a human picks is re-rendered at the final tier.
 */
enum ImageQualityTier: string
{
    case Draft = 'draft';
    case Final = 'final';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Final => 'Final',
        };
    }
}
