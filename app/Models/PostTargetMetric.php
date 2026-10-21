<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step M-3.1 / I-10.1 (Phase 12) — one engagement reading for one published channel, as the vendor
 * reported it at `fetched_at`. Append-only: every sync adds a row, so trends stay queryable;
 * `post_targets.latest_metrics_id` points at the newest. A null metric means the platform didn't
 * report it — never treat it as zero. `engagement` = likes + comments + shares + saves (the
 * reported ones), null when none were reported.
 *
 * @property int $id
 * @property int $org_id
 * @property int $post_target_id
 * @property int|null $impressions
 * @property int|null $reach
 * @property int|null $views
 * @property int|null $likes
 * @property int|null $comments
 * @property int|null $shares
 * @property int|null $saves
 * @property int|null $clicks
 * @property int|null $engagement
 * @property string $source
 * @property Carbon $fetched_at
 */
#[Fillable([
    'org_id', 'post_target_id', 'impressions', 'reach', 'views', 'likes', 'comments', 'shares',
    'saves', 'clicks', 'engagement', 'source', 'fetched_at',
])]
class PostTargetMetric extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
            'impressions' => 'integer',
            'reach' => 'integer',
            'views' => 'integer',
            'likes' => 'integer',
            'comments' => 'integer',
            'shares' => 'integer',
            'saves' => 'integer',
            'clicks' => 'integer',
            'engagement' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PostTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class, 'post_target_id');
    }
}
