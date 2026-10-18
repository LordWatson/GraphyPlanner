<?php

namespace App\Enums;

/**
 * Step C-8.1 — how a campaign is paid for. Only a fixed fee carries a `fee_amount`.
 */
enum CampaignBillingType: string
{
    case IncludedInRetainer = 'included_in_retainer';
    case FixedFee = 'fixed_fee';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::IncludedInRetainer => 'Included in retainer',
            self::FixedFee => 'Fixed fee',
            self::None => 'Not billed',
        };
    }
}
