<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step GTM-5.1 — one ad account's figures for one day. A day the platform wouldn't report has
 * **no row**, and a field it didn't report stays null: a paid-ads report that invents a zero spend
 * is worse than one that says it doesn't know.
 *
 * @property int $id
 * @property int $org_id
 * @property int $ad_account_id
 * @property Carbon $captured_on
 * @property int|null $spend_minor
 * @property int|null $impressions
 * @property int|null $clicks
 * @property int|null $conversions
 * @property Carbon $fetched_at
 */
#[Fillable(['org_id', 'ad_account_id', 'captured_on', 'spend_minor', 'impressions', 'clicks', 'conversions', 'fetched_at'])]
class AdAccountMetric extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['captured_on' => 'date', 'fetched_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<AdAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class, 'ad_account_id');
    }
}
