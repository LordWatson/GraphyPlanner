<?php

namespace App\Actions\Analytics;

use App\Contracts\EngagementSource;
use App\Exceptions\Analytics\EngagementSourceException;
use App\Models\PostTarget;
use App\Models\PostTargetMetric;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step M-3.1 / Phase 12 Step 12.1 — read one published channel's engagement from the
 * `EngagementSource` and append it to `post_target_metrics` (history, never an upsert), pointing
 * `post_targets.latest_metrics_id` at it. Also keeps the network's post id / URL when the vendor
 * reports them.
 *
 * A vendor failure is recorded on the target (`metrics_error`, safe text) and logged; it never
 * throws, so one bad channel can't stop an organization's sweep. "Nothing to report" (null) only
 * stamps `metrics_synced_at`.
 */
class SyncPostTargetMetricsAction
{
    public function __construct(private readonly EngagementSource $source) {}

    /**
     * @return 'stored'|'empty'|'failed'
     */
    public function __invoke(PostTarget $target): string
    {
        $context = [
            'post_target_id' => $target->id,
            'post_id' => $target->post_id,
            'social_account_id' => $target->social_account_id,
        ];

        try {
            $reading = $this->source->postMetrics($target);
        } catch (EngagementSourceException $e) {
            $target->forceFill(['metrics_synced_at' => now(), 'metrics_error' => $e->getMessage()])->saveQuietly();
            Log::warning('Post metrics sync failed', [...$context, 'error' => $e->getMessage()]);

            return 'failed';
        }

        $refs = array_filter([
            'platform_post_id' => $target->platform_post_id === null ? $reading?->platformPostId : null,
            'post_url' => $target->post_url === null ? $reading?->postUrl : null,
        ], fn (?string $value) => $value !== null);

        if ($reading === null || $reading->isEmpty()) {
            $target->forceFill(['metrics_synced_at' => now(), 'metrics_error' => null, ...$refs])->saveQuietly();

            return 'empty';
        }

        DB::transaction(function () use ($target, $reading, $refs) {
            $orgId = (int) DB::table('posts')->where('id', $target->post_id)->value('org_id');

            $metric = PostTargetMetric::query()->create([
                ...$reading->toMetricColumns(),
                'org_id' => $orgId,
                'post_target_id' => $target->id,
                'source' => $this->source->key(),
                'fetched_at' => now(),
            ]);

            $target->forceFill([
                'latest_metrics_id' => $metric->id,
                'metrics_synced_at' => now(),
                'metrics_error' => null,
                ...$refs,
            ])->saveQuietly();
        });

        Log::info('Post metrics stored', [...$context, 'engagement' => $reading->engagement()]);

        return 'stored';
    }
}
