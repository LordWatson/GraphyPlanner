<?php

namespace App\Actions\Benchmarks;

use App\Contracts\CompetitorProfileSource;
use App\Models\TrackedProfile;
use App\Models\TrackedProfileMetric;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step GTM-4.1 — read one tracked profile and keep **one row per day**, upserted, exactly like
 * `SyncSocialAccountMetricsAction` does for our own accounts. A second run on the same day
 * overwrites that day's figures rather than adding a second row, so the series can never
 * double-count.
 *
 * The three outcomes are deliberately distinguishable, because "the platform wouldn't say" and
 * "the figure is zero" are different facts:
 *
 *  - `stored` — a reading with at least one figure; only the reported fields are written.
 *  - `gap` — the source returned nothing. **No row is written**, the profile records why, and the
 *    chart shows a gap. Nothing here ever writes a zero it wasn't given.
 */
class SyncTrackedProfileAction
{
    public function __construct(private readonly CompetitorProfileSource $source) {}

    /**
     * @return 'stored'|'gap'
     */
    public function __invoke(TrackedProfile $profile): string
    {
        $context = [
            'tracked_profile_id' => $profile->id,
            'org_id' => $profile->org_id,
            'platform' => $profile->platform->value,
            'source' => $this->source->key(),
        ];

        $reading = $this->source->profile($profile);

        if ($reading === null || $reading->isEmpty()) {
            $profile->forceFill([
                'last_synced_at' => now(),
                'last_error' => __('No public figures were available for this profile.'),
            ])->saveQuietly();

            Log::info('Tracked profile reading unavailable', $context);

            return 'gap';
        }

        // The profile's own day, not the server's: a benchmark row is compared against our
        // `social_account_metrics`, which are kept in the account's timezone.
        $today = now($this->timezone($profile))->toDateString();
        $now = now();

        DB::transaction(function () use ($profile, $reading, $today, $now) {
            $row = TrackedProfileMetric::query()
                ->withoutOrgScope()
                ->where('tracked_profile_id', $profile->id)
                ->whereDate('captured_on', $today)
                ->first();

            if ($row === null) {
                TrackedProfileMetric::create([
                    'org_id' => $profile->org_id,
                    'tracked_profile_id' => $profile->id,
                    'captured_on' => $today,
                    'fetched_at' => $now,
                    ...$reading->toColumns(),
                ]);
            } else {
                $row->forceFill([...$reading->toColumns(), 'fetched_at' => $now])->save();
            }

            $profile->forceFill(['last_synced_at' => $now, 'last_error' => null])->saveQuietly();
        });

        Log::info('Tracked profile reading stored', [...$context, 'followers' => $reading->followers]);

        return 'stored';
    }

    private function timezone(TrackedProfile $profile): string
    {
        $timezone = (string) ($profile->client?->organization?->default_timezone ?? 'UTC');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
