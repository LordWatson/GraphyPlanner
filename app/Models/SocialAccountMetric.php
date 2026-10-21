<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step M-3.1 / I-10.1 — one social account's figures for one day in the account's timezone:
 * the follower count captured that day, and the day's reach / impressions where the network
 * reports a daily series. Upserted per (account, day) by `SyncSocialAccountMetricsAction`.
 *
 * @property int $id
 * @property int $org_id
 * @property int $social_account_id
 * @property Carbon $metric_date
 * @property int|null $followers
 * @property int|null $reach
 * @property int|null $impressions
 * @property int|null $profile_views
 * @property Carbon $fetched_at
 */
#[Fillable(['org_id', 'social_account_id', 'metric_date', 'followers', 'reach', 'impressions', 'profile_views', 'fetched_at'])]
class SocialAccountMetric extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'fetched_at' => 'datetime',
            'followers' => 'integer',
            'reach' => 'integer',
            'impressions' => 'integer',
            'profile_views' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SocialAccount, $this>
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class)->withoutGlobalScope(OrganizationScope::class);
    }
}
