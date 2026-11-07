<?php

namespace App\Services\Analytics\Ads;

use App\Contracts\AdsSource;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Models\AdAccount;
use App\Models\Scopes\OrganizationScope;
use App\Models\SocialAccount;
use App\Services\Grids\MetaGraphProfileFeedSource;
use App\Support\Analytics\Ads\AdMetricsReading;
use App\Support\Publishing\VendorHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Step GTM-5.1 — Meta ad spend from the Marketing API's insights endpoint:
 * `GET /act_{id}/insights?fields=spend,impressions,clicks,actions&time_increment=1`.
 *
 * **Reads only.** It calls one endpoint, and that endpoint cannot change anything. See
 * {@see AdsSource} for why that is a design rule rather than an implementation detail.
 *
 * It needs `ads_read` on our own Facebook app, which is Phase 5 work and is **not granted today**.
 * Until it is, Meta answers 403 and this returns null, so an agency sees honest gaps instead of
 * wrong figures — and `config('ads.provider')` defaults to none, so it isn't even asked.
 *
 * Never throws: no token, no permission, a 4xx/5xx or an unreachable host all return null, and the
 * caller records the reason on the ad account.
 */
class MetaAdsSource implements AdsSource
{
    public function key(): string
    {
        return 'meta_marketing';
    }

    public function accountMetrics(AdAccount $account, int $sinceDays): ?AdMetricsReading
    {
        if (! in_array($account->platform, [Platform::Facebook, Platform::Instagram], true)) {
            return null;
        }

        $token = $this->token($account);

        if ($token === null) {
            return null;
        }

        $since = now()->subDays(max(1, $sinceDays))->toDateString();
        $until = now()->toDateString();

        try {
            $response = VendorHttp::forReads(
                Http::baseUrl($this->baseUrl())->acceptJson()->withQueryParameters(['access_token' => $token]),
            )->get('/act_'.ltrim($account->external_account_id, 'act_').'/insights', [
                'fields' => 'spend,impressions,clicks,actions',
                'time_increment' => 1,
                'time_range' => json_encode(['since' => $since, 'until' => $until]),
                'limit' => 500,
            ]);
        } catch (ConnectionException $e) {
            Log::info('Meta Ads insights could not be reached', ['ad_account_id' => $account->id, 'reason' => $e::class]);

            return null;
        }

        if (! $response->successful()) {
            // The body echoes the request, so only the status and Meta's own code are logged.
            Log::info('Meta Ads insights request rejected', [
                'ad_account_id' => $account->id,
                'status' => $response->status(),
                'error_code' => $response->json('error.code'),
            ]);

            return null;
        }

        return $this->toReading($response->json('data'));
    }

    /**
     * @param  mixed  $rows  Meta's `data` array, one object per day
     */
    private function toReading(mixed $rows): ?AdMetricsReading
    {
        if (! is_array($rows) || $rows === []) {
            return null;
        }

        $daily = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['date_start'] ?? null)) {
                continue;
            }

            $date = substr($row['date_start'], 0, 10);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }

            $daily[$date] = [
                // Meta reports spend as a decimal string in the account's currency.
                'spend_minor' => isset($row['spend']) && is_numeric($row['spend'])
                    ? (int) round(((float) $row['spend']) * 100)
                    : null,
                'impressions' => self::int($row['impressions'] ?? null),
                'clicks' => self::int($row['clicks'] ?? null),
                'conversions' => self::conversions($row['actions'] ?? null),
            ];
        }

        ksort($daily);

        return $daily === [] ? null : new AdMetricsReading(daily: $daily);
    }

    /**
     * Meta returns every action type; a "conversion" here is a purchase or a lead, because those
     * are the two an agency reports on. A page like or a post comment is engagement, not a
     * conversion, and counting it as one would flatter the numbers.
     */
    private static function conversions(mixed $actions): ?int
    {
        if (! is_array($actions)) {
            return null;
        }

        $wanted = ['purchase', 'omni_purchase', 'lead', 'offsite_conversion.fb_pixel_purchase', 'offsite_conversion.fb_pixel_lead'];
        $total = null;

        foreach ($actions as $action) {
            if (is_array($action) && in_array($action['action_type'] ?? null, $wanted, true)) {
                $total = ($total ?? 0) + (int) ($action['value'] ?? 0);
            }
        }

        return $total;
    }

    /**
     * The brand's own Facebook Login token. Ads insights are read with the token of a user who has
     * access to the ad account, which is the same token the grid preview and Business Discovery
     * already use.
     */
    private function token(AdAccount $account): ?string
    {
        $social = SocialAccount::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('client_id', $account->client_id)
            ->where('connection_status', ConnectionStatus::Connected)
            ->get()
            ->first(fn (SocialAccount $candidate) => MetaGraphProfileFeedSource::canRead($candidate));

        return $social?->meta_access_token;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function baseUrl(): string
    {
        $base = rtrim((string) config('services.facebook.graph_base_url', 'https://graph.facebook.com'), '/');

        return $base.'/'.config('services.facebook.graph_version', 'v20.0');
    }
}
