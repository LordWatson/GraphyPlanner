<?php

namespace App\Services\Grids;

use App\Contracts\ProfileFeedSource;
use App\Models\SocialAccount;
use App\Support\Grids\ProfileFeed;
use App\Support\Grids\ProfileFeedItem;
use App\Support\Publishing\VendorHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Step 18.3 — the whole Instagram profile from Meta's Graph API, using the Step 1.9 Facebook
 * Login token: `GET /{ig-user-id}?fields=username,profile_picture_url,media_count` and
 * `GET /{ig-user-id}/media?fields=…`. Same timeouts and jittered retries as the other Graph calls
 * (`VendorHttp::forReads`). Cached per account for `grids.feed_cache_minutes` (`grid-feed:{id}`).
 *
 * Never logs the token or any media URL, and only keeps `https` media URLs. Throws a
 * `RuntimeException` on any failure; `CompositeProfileFeedSource` falls back.
 */
class MetaGraphProfileFeedSource implements ProfileFeedSource
{
    public static function cacheKey(SocialAccount $account): string
    {
        return "grid-feed:{$account->id}";
    }

    /**
     * Whether this account can be read from the Graph API at all.
     */
    public static function canRead(SocialAccount $account): bool
    {
        return filled($account->meta_access_token)
            && filled($account->meta_instagram_user_id)
            && ($account->meta_access_token_expires_at === null || $account->meta_access_token_expires_at->isFuture());
    }

    public function recent(SocialAccount $account, int $limit = 30): ProfileFeed
    {
        if (! self::canRead($account)) {
            throw new RuntimeException('This Instagram account is not connected through Facebook Login.');
        }

        /** @var array<string, mixed> $cached */
        $cached = Cache::remember(
            self::cacheKey($account),
            now()->addMinutes(max(1, (int) config('grids.feed_cache_minutes', 15))),
            fn () => $this->fetch($account, $limit)->toArray(),
        );

        return ProfileFeed::fromArray($cached);
    }

    public function forget(SocialAccount $account): void
    {
        Cache::forget(self::cacheKey($account));
    }

    private function fetch(SocialAccount $account, int $limit): ProfileFeed
    {
        $userId = (string) $account->meta_instagram_user_id;

        Log::info('Fetching Instagram profile feed from the Graph API', ['social_account_id' => $account->id]);

        $profile = $this->send($account, fn (PendingRequest $request) => $request->get("/{$userId}", [
            'fields' => 'username,profile_picture_url,media_count',
        ]));
        $media = $this->send($account, fn (PendingRequest $request) => $request->get("/{$userId}/media", [
            'fields' => 'id,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp',
            'limit' => max(1, min(50, $limit)),
        ]));

        $items = [];

        foreach ((array) ($media->json('data') ?? []) as $row) {
            if (! is_array($row) || ($row['media_product_type'] ?? null) === 'STORY') {
                continue;
            }

            $type = (string) ($row['media_type'] ?? 'IMAGE');

            // A video (or a carousel opening on one) shows its thumbnail on the grid.
            $thumbnail = $type === 'VIDEO'
                ? ($row['thumbnail_url'] ?? null)
                : ($row['thumbnail_url'] ?? $row['media_url'] ?? null);

            $items[] = new ProfileFeedItem(
                id: (string) ($row['id'] ?? ''),
                mediaType: $type,
                thumbnailUrl: self::safeUrl($thumbnail),
                permalink: self::safeUrl($row['permalink'] ?? null),
                publishedAt: isset($row['timestamp']) ? (string) $row['timestamp'] : null,
            );

            if (count($items) >= $limit) {
                break;
            }
        }

        Log::info('Instagram profile feed fetched', [
            'social_account_id' => $account->id,
            'item_count' => count($items),
        ]);

        return new ProfileFeed(
            username: (string) ($profile->json('username') ?: ltrim($account->handle, '@')),
            profilePictureUrl: self::safeUrl($profile->json('profile_picture_url')),
            mediaCount: is_numeric($profile->json('media_count')) ? (int) $profile->json('media_count') : null,
            items: $items,
            source: ProfileFeed::SOURCE_META,
        );
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(SocialAccount $account, callable $call): Response
    {
        try {
            $response = $call(VendorHttp::forReads(Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->withQueryParameters(['access_token' => $account->meta_access_token])));
        } catch (ConnectionException $e) {
            throw new RuntimeException('The Instagram Graph API could not be reached.', previous: $e);
        }

        if (! $response->successful()) {
            // The body can echo request details, so only the status and Meta's error code go to the log.
            Log::warning('Instagram profile feed request rejected', [
                'social_account_id' => $account->id,
                'status' => $response->status(),
                'error_code' => $response->json('error.code'),
            ]);

            throw new RuntimeException("The Instagram Graph API answered {$response->status()}.");
        }

        return $response;
    }

    private function baseUrl(): string
    {
        $base = rtrim((string) config('services.facebook.graph_base_url', 'https://graph.facebook.com'), '/');

        return $base.'/'.config('services.facebook.graph_version', 'v20.0');
    }

    private static function safeUrl(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }
}
