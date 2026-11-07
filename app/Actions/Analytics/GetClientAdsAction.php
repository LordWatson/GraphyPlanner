<?php

namespace App\Actions\Analytics;

use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\AdAccount;
use App\Models\Client;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\DB;

/**
 * Step GTM-5.2 — the read-only paid-ads payload for one brand: spend and results per ad account
 * over `ads.window_days`, the totals, and the organic figures beside them.
 *
 * Same honesty rule as the benchmark panel: **every figure is nullable and a gap is a gap.** A
 * disconnected account, or one the platform wouldn't answer for, comes back with `has_data => false`
 * and nulls plus the reason — never a zero, which in a spend report would read as "we spent
 * nothing" and is a different claim entirely.
 *
 * CPC and CPA are derived here (spend ÷ clicks, spend ÷ conversions) rather than stored, so they
 * can never disagree with the figures above them, and are null whenever the denominator is 0 or
 * unknown.
 */
class GetClientAdsAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Client $brand): array
    {
        $organization = $brand->organization;
        $entitled = $organization !== null && $this->entitlements->allows($organization, Feature::AdsReporting);

        $windowDays = max(1, (int) config('ads.window_days', 30));
        $since = now()->subDays($windowDays)->toDateString();

        if (! $entitled) {
            return [
                'entitled' => false,
                'window_days' => $windowDays,
                'default_currency' => $brand->billingCurrency(),
                'accounts' => [],
                'totals' => self::emptyTotals(),
                'organic' => $this->organic($brand, $since),
            ];
        }

        $accounts = AdAccount::query()
            ->withoutOrgScope()
            ->where('client_id', $brand->id)
            ->orderBy('id')
            ->get();

        $sums = $this->sums($accounts->pluck('id')->all(), $since);
        $rows = [];
        $totals = self::emptyTotals();

        foreach ($accounts as $account) {
            $row = $sums[$account->id] ?? null;

            $rows[] = [
                'id' => $account->id,
                'name' => $account->displayName(),
                'platform' => $account->platform->value,
                'platform_label' => $account->platform->label(),
                'connected' => $account->isConnected(),
                'currency' => $account->currency,
                'has_data' => $row !== null,
                'spend_minor' => self::int($row?->spend_minor),
                'impressions' => self::int($row?->impressions),
                'clicks' => self::int($row?->clicks),
                'conversions' => self::int($row?->conversions),
                'cpc_minor' => self::ratio(self::int($row?->spend_minor), self::int($row?->clicks)),
                'cpa_minor' => self::ratio(self::int($row?->spend_minor), self::int($row?->conversions)),
                'last_error' => $account->isConnected() ? $account->metrics_error : __('Not connected.'),
                'last_synced_at' => $account->metrics_synced_at?->toIso8601String(),
            ];

            foreach (['spend_minor', 'impressions', 'clicks', 'conversions'] as $field) {
                $value = self::int($row?->{$field});

                if ($value !== null) {
                    $totals[$field] = ($totals[$field] ?? 0) + $value;
                }
            }

            $totals['currency'] ??= $account->currency;
        }

        $totals['cpc_minor'] = self::ratio($totals['spend_minor'], $totals['clicks']);
        $totals['cpa_minor'] = self::ratio($totals['spend_minor'], $totals['conversions']);

        return [
            'entitled' => true,
            'window_days' => $windowDays,
            // An ad account reports in its own currency; this is only the fallback for a label
            // before the platform has told us which that is. Nothing is ever converted.
            'default_currency' => $brand->billingCurrency(),
            'accounts' => $rows,
            'totals' => $totals,
            'organic' => $this->organic($brand, $since),
        ];
    }

    /**
     * One grouped read for every account's window, so the panel is one query however many accounts
     * there are.
     *
     * @param  list<int>  $accountIds
     * @return array<int, object>
     */
    private function sums(array $accountIds, string $since): array
    {
        if ($accountIds === []) {
            return [];
        }

        $rows = DB::table('ad_account_metrics')
            ->whereIn('ad_account_id', $accountIds)
            ->where('captured_on', '>=', $since)
            ->groupBy('ad_account_id')
            ->get([
                'ad_account_id',
                DB::raw('sum(spend_minor) as spend_minor'),
                DB::raw('sum(impressions) as impressions'),
                DB::raw('sum(clicks) as clicks'),
                DB::raw('sum(conversions) as conversions'),
            ]);

        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(int) $row->ad_account_id] = $row;
        }

        return $keyed;
    }

    /**
     * What the brand got without paying for it, over the same window — the other half of
     * "organic vs paid". Interactions are null rather than 0 when nothing was measured.
     *
     * @return array{posts: int, engagement: int|null}
     */
    private function organic(Client $brand, string $since): array
    {
        $accountIds = SocialAccount::query()
            ->withoutOrgScope()
            ->where('client_id', $brand->id)
            ->pluck('id');

        if ($accountIds->isEmpty()) {
            return ['posts' => 0, 'engagement' => null];
        }

        $targets = DB::table('post_targets')
            ->whereIn('social_account_id', $accountIds)
            ->where('status', PostTargetStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', $since);

        $engagement = DB::table('post_target_metrics')
            ->whereIn('post_target_id', (clone $targets)->select('id'))
            ->whereNotNull('engagement')
            ->sum('engagement');

        return [
            'posts' => (clone $targets)->count(),
            'engagement' => $engagement > 0 ? (int) $engagement : null,
        ];
    }

    /**
     * The platforms an ad account can be reported from at all.
     *
     * @return list<Platform>
     */
    public static function supportedPlatforms(): array
    {
        return [Platform::Facebook, Platform::Instagram];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function emptyTotals(): array
    {
        return [
            'spend_minor' => null,
            'impressions' => null,
            'clicks' => null,
            'conversions' => null,
            'cpc_minor' => null,
            'cpa_minor' => null,
            'currency' => null,
        ];
    }

    private static function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * Null unless both halves are known and the denominator is more than zero — a cost per click
     * with no clicks is not infinity, it is unanswerable.
     */
    private static function ratio(?int $spend, ?int $count): ?int
    {
        return $spend === null || $count === null || $count <= 0 ? null : (int) round($spend / $count);
    }
}
