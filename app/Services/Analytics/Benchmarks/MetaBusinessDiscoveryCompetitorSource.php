<?php

namespace App\Services\Analytics\Benchmarks;

use App\Contracts\CompetitorProfileSource;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Models\Scopes\OrganizationScope;
use App\Models\SocialAccount;
use App\Models\TrackedProfile;
use App\Services\Grids\MetaGraphProfileFeedSource;
use App\Support\Analytics\Benchmarks\CompetitorProfileReading;
use App\Support\Publishing\VendorHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Step GTM-4.1 — Instagram competitor figures through Meta's **Business Discovery**:
 * `GET /{our-ig-user-id}?fields=business_discovery.username({their-handle}){followers_count,
 * media_count,media{like_count,comments_count}}`.
 *
 * This is the endpoint Meta provides for exactly this job, and it is the reason benchmarking here
 * is one platform rather than four: it is asked **through an IG Business account the brand itself
 * owns** (the same Facebook Login token the grid preview uses), returns only figures Instagram
 * already shows publicly, and needs no agreement with the profile being read. No other network we
 * publish to offers a comparable read, and none of them would be read by scraping — see
 * `benchmarks.md`.
 *
 * Never throws: a brand with no Meta-connected Instagram account, a lapsed token, a private or
 * non-business target, or a 4xx/5xx from Meta all return null, and the caller records a gap.
 */
class MetaBusinessDiscoveryCompetitorSource implements CompetitorProfileSource
{
    public function key(): string
    {
        return 'meta_business_discovery';
    }

    public function profile(TrackedProfile $profile): ?CompetitorProfileReading
    {
        if ($profile->platform !== Platform::Instagram) {
            return null;
        }

        $via = $this->readableAccount($profile);

        if ($via === null) {
            return null;
        }

        $handle = TrackedProfile::normaliseHandle($profile->handle);
        $posts = max(1, min(25, (int) config('benchmarks.engagement_posts', 12)));

        try {
            $response = VendorHttp::forReads(
                Http::baseUrl($this->baseUrl())
                    ->acceptJson()
                    ->withQueryParameters(['access_token' => $via->meta_access_token]),
            )->get('/'.$via->meta_instagram_user_id, [
                'fields' => sprintf(
                    'business_discovery.username(%s){followers_count,media_count,media.limit(%d){like_count,comments_count}}',
                    $handle,
                    $posts,
                ),
            ]);
        } catch (ConnectionException $e) {
            Log::info('Business Discovery could not be reached', [
                'tracked_profile_id' => $profile->id,
                'reason' => $e::class,
            ]);

            return null;
        }

        if (! $response->successful()) {
            // The body echoes the request, so only the status and Meta's own code are logged.
            Log::info('Business Discovery request rejected', [
                'tracked_profile_id' => $profile->id,
                'status' => $response->status(),
                'error_code' => $response->json('error.code'),
            ]);

            return null;
        }

        $discovery = $response->json('business_discovery');

        if (! is_array($discovery)) {
            return null;
        }

        return new CompetitorProfileReading(
            followers: self::int($discovery['followers_count'] ?? null),
            postCount: self::int($discovery['media_count'] ?? null),
            avgEngagement: self::averageEngagement($discovery['media']['data'] ?? null),
        );
    }

    /**
     * An Instagram account **belonging to this brand** that can ask Meta on its behalf. Business
     * Discovery is always asked through an account we own, so without one there is no reading —
     * which is also why a brand's own Instagram has to be connected before it can be benchmarked.
     */
    private function readableAccount(TrackedProfile $profile): ?SocialAccount
    {
        return SocialAccount::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('client_id', $profile->client_id)
            ->where('platform', Platform::Instagram)
            ->where('connection_status', ConnectionStatus::Connected)
            ->get()
            ->first(fn (SocialAccount $account) => MetaGraphProfileFeedSource::canRead($account));
    }

    /**
     * Interactions per post over the posts Meta returned, rounded. Null when it returned none —
     * a brand-new or empty profile has no average, and 0 would read as "nobody engages".
     */
    private static function averageEngagement(mixed $media): ?int
    {
        if (! is_array($media) || $media === []) {
            return null;
        }

        $total = 0;
        $counted = 0;

        foreach ($media as $post) {
            if (! is_array($post)) {
                continue;
            }

            $total += (int) ($post['like_count'] ?? 0) + (int) ($post['comments_count'] ?? 0);
            $counted++;
        }

        return $counted === 0 ? null : (int) round($total / $counted);
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
