<?php

namespace App\Actions\Analytics;

use App\Contracts\AdsSource;
use App\Enums\ConnectionStatus;
use App\Models\AdAccount;
use App\Models\AdAccountMetric;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step GTM-5.1 — read one ad account's daily series and keep one `ad_account_metrics` row per day,
 * upserted, exactly as `SyncSocialAccountMetricsAction` does for our own accounts.
 *
 * It re-reads the **whole window** every night rather than only yesterday, because ad platforms
 * restate recent days as conversions land; the upsert is what makes that safe.
 *
 * Three outcomes, kept distinguishable because "disconnected", "nothing to report" and "spent
 * nothing" are different facts:
 *
 *  - `stored` — at least one day had at least one figure.
 *  - `empty` — the source answered with nothing. No rows are written and no error is recorded.
 *  - `failed` — the account isn't connected, or the source couldn't read it. The reason goes on the
 *    account and the report shows a gap. **Nothing here ever writes a zero it wasn't given.**
 */
class SyncAdAccountMetricsAction
{
    public function __construct(private readonly AdsSource $source) {}

    /**
     * @return 'stored'|'empty'|'failed'
     */
    public function __invoke(AdAccount $account): string
    {
        $context = ['ad_account_id' => $account->id, 'org_id' => $account->org_id, 'source' => $this->source->key()];

        if (! $account->isConnected()) {
            Log::info('Ad account sync skipped: not connected', $context);

            return 'failed';
        }

        $reading = $this->source->accountMetrics($account, max(1, (int) config('ads.window_days', 30)));

        if ($reading === null) {
            $account->forceFill([
                'connection_status' => ConnectionStatus::NotConnected,
                'metrics_synced_at' => now(),
                'metrics_error' => __('No figures came back for this ad account. Check our access to it.'),
            ])->saveQuietly();

            Log::info('Ad account reading unavailable', $context);

            return 'failed';
        }

        if ($reading->isEmpty()) {
            $account->forceFill(['metrics_synced_at' => now(), 'metrics_error' => null])->saveQuietly();

            return 'empty';
        }

        $today = now()->toDateString();
        $now = now();

        DB::transaction(function () use ($account, $reading, $today, $now) {
            foreach (array_keys($reading->daily) as $date) {
                $values = $reading->columnsFor($date);

                if ($values === [] || $date > $today) {
                    continue;
                }

                $row = AdAccountMetric::query()
                    ->withoutOrgScope()
                    ->where('ad_account_id', $account->id)
                    ->whereDate('captured_on', $date)
                    ->first();

                if ($row === null) {
                    AdAccountMetric::create([
                        'org_id' => $account->org_id,
                        'ad_account_id' => $account->id,
                        'captured_on' => $date,
                        'fetched_at' => $now,
                        ...$values,
                    ]);

                    continue;
                }

                $row->forceFill([...$values, 'fetched_at' => $now])->save();
            }

            $account->forceFill([
                'currency' => $account->currency ?: $reading->currency,
                'name' => $account->name ?: $reading->name,
                'metrics_synced_at' => $now,
                'metrics_error' => null,
            ])->saveQuietly();
        });

        Log::info('Ad account metrics stored', [...$context, 'days' => count($reading->daily)]);

        return 'stored';
    }
}
