<?php

namespace App\Actions\Analytics;

use App\Contracts\EngagementSource;
use App\Exceptions\Analytics\EngagementSourceException;
use App\Models\SocialAccount;
use App\Models\SocialAccountMetric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step M-3.1 / I-10.1 — read one social account's figures from the `EngagementSource` and keep
 * one `social_account_metrics` row per day in the **account's** timezone:
 *
 * - today's row gets the follower count and profile views as they stand now (re-reads the same day
 *   overwrite them, so the day ends with its latest count);
 * - every date in the vendor's daily series gets that day's reach / impressions — without touching
 *   a follower count already captured for it.
 *
 * Failures are recorded on the account (`metrics_error`) and logged, never thrown.
 */
class SyncSocialAccountMetricsAction
{
    public function __construct(private readonly EngagementSource $source) {}

    /**
     * @return 'stored'|'empty'|'failed'
     */
    public function __invoke(SocialAccount $account): string
    {
        $context = ['social_account_id' => $account->id, 'org_id' => $account->org_id, 'platform' => $account->platform->value];

        try {
            $reading = $this->source->accountMetrics($account);
        } catch (EngagementSourceException $e) {
            $account->forceFill(['metrics_synced_at' => now(), 'metrics_error' => $e->getMessage()])->saveQuietly();
            Log::warning('Account metrics sync failed', [...$context, 'error' => $e->getMessage()]);

            return 'failed';
        }

        if ($reading === null || $reading->isEmpty()) {
            $account->forceFill(['metrics_synced_at' => now(), 'metrics_error' => null])->saveQuietly();

            return 'empty';
        }

        $timezone = $this->timezone($account);
        $today = CarbonImmutable::now($timezone)->toDateString();
        $now = now();

        DB::transaction(function () use ($account, $reading, $today, $now) {
            foreach ($reading->daily as $date => $values) {
                $values = array_filter($values, fn (?int $value) => $value !== null);

                if ($values === [] || $date > $today) {
                    continue;
                }

                $this->upsert($account, $date, $values, $now);
            }

            $this->upsert($account, $today, array_filter([
                'followers' => $reading->followers,
                'profile_views' => $reading->profileViews,
            ], fn (?int $value) => $value !== null), $now);

            $account->forceFill(['metrics_synced_at' => $now, 'metrics_error' => null])->saveQuietly();
        });

        Log::info('Account metrics stored', [...$context, 'days' => count($reading->daily), 'followers' => $reading->followers]);

        return 'stored';
    }

    /**
     * @param  array<string, int>  $values
     */
    private function upsert(SocialAccount $account, string $date, array $values, \DateTimeInterface $now): void
    {
        $row = SocialAccountMetric::query()
            ->where('social_account_id', $account->id)
            ->whereDate('metric_date', $date)
            ->first();

        if ($row === null) {
            SocialAccountMetric::query()->create([
                'org_id' => $account->org_id,
                'social_account_id' => $account->id,
                'metric_date' => $date,
                'fetched_at' => $now,
                ...$values,
            ]);

            return;
        }

        $row->forceFill([...$values, 'fetched_at' => $now])->save();
    }

    private function timezone(SocialAccount $account): string
    {
        $timezone = $account->timezone;

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
