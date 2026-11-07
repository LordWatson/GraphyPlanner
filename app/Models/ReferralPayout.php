<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step GTM-7.3 — a record of money actually sent to a partner, not a calculation. What is *owed*
 * is derived whenever somebody asks; this is what has been settled, so the balance never needs
 * recomputing from scratch.
 *
 * @property int $id
 * @property int $referral_partner_id
 * @property int $amount_minor
 * @property string $currency
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon|null $paid_at
 * @property string|null $reference
 */
#[Fillable(['referral_partner_id', 'amount_minor', 'currency', 'period_start', 'period_end', 'paid_at', 'reference'])]
class ReferralPayout extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_at' => 'datetime',
            'amount_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ReferralPartner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(ReferralPartner::class, 'referral_partner_id');
    }
}
