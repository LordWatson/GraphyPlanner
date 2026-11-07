<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step GTM-4.1 — one tracked profile's public figures on one day. A day the platform wouldn't
 * answer for has **no row**: the chart then shows a gap, which is the truth, rather than a zero,
 * which isn't.
 *
 * @property int $id
 * @property int $org_id
 * @property int $tracked_profile_id
 * @property Carbon $captured_on
 * @property int|null $followers
 * @property int|null $post_count
 * @property int|null $avg_engagement
 * @property Carbon $fetched_at
 */
#[Fillable(['org_id', 'tracked_profile_id', 'captured_on', 'followers', 'post_count', 'avg_engagement', 'fetched_at'])]
class TrackedProfileMetric extends Model
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
     * @return BelongsTo<TrackedProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(TrackedProfile::class, 'tracked_profile_id');
    }
}
