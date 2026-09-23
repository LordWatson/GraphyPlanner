<?php

namespace App\Services\Publishing;

use App\Contracts\MusicProvider;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Exceptions\Publishing\MusicProviderUnavailableException;
use App\Models\SocialAccount;
use App\Support\Publishing\MusicTrack;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MusicProvider implementation for Upload-Post's sound catalogs (Step 1.8.2). Only TikTok is
 * actually backed by a real vendor endpoint — per the real API reference
 * (https://docs.upload-post.com/api/search-tiktok-music,
 * https://docs.upload-post.com/api/get-tiktok-music), Upload-Post exposes
 * `GET /uploadposts/tiktok/music/{search,trending}`, both scoped to a *connected TikTok profile's*
 * cached commercial-music charts (there is no cross-platform or Instagram sound-library endpoint
 * anywhere in Upload-Post's API — Instagram's own audio search is a separate Meta Graph API
 * endpoint Upload-Post doesn't proxy). Instagram therefore always returns an empty/`null` result
 * here rather than calling the vendor, same as `UploadPostAdapter::applyInstagramFields()` already
 * treats `Post::music` for Instagram as `audio_id`/`audio_name` supplied by hand.
 *
 * Unlike `UploadPostAdapter`, `MusicProvider::search()`/`find()` (Step 1.8.1) carry no
 * `SocialAccount`/organization parameter, but the vendor's `profile` query param requires a
 * connected TikTok account's username to scope the search — so the org is resolved from the
 * authenticated user the same way every controller in this app resolves it
 * (`$request->user()->organization`, see `.junie/modules/settings.md`), and the profile from that
 * org's first connected TikTok `SocialAccount`. No API key/profile ⇒ an empty/`null` result and a
 * warning log, never a vendor call.
 */
class UploadPostMusicProvider implements MusicProvider
{
    public function __construct(
        private readonly ?string $baseUrl = null,
    ) {}

    /**
     * @return Collection<int, MusicTrack>
     */
    public function search(string $query, Platform $platform): Collection
    {
        if ($platform !== Platform::TikTok) {
            Log::info('Upload-Post music search skipped: platform not supported', [
                'platform' => $platform->value,
            ]);

            return new Collection;
        }

        $account = $this->connectedTikTokAccount();

        if ($account === null) {
            Log::warning('Upload-Post music search skipped: no connected TikTok account for the current organization');

            throw new MusicProviderUnavailableException('Connect a TikTok account before searching for music.');
        }

        $tracks = $this->fetchTracks($account, ['q' => $query]);

        return $tracks->map(fn (array $track): MusicTrack => $this->toMusicTrack($track, $platform));
    }

    public function find(string $id, Platform $platform): ?MusicTrack
    {
        if ($platform !== Platform::TikTok) {
            Log::info('Upload-Post music lookup skipped: platform not supported', [
                'platform' => $platform->value,
            ]);

            return null;
        }

        $account = $this->connectedTikTokAccount();

        if ($account === null) {
            Log::warning('Upload-Post music lookup skipped: no connected TikTok account for the current organization');

            throw new MusicProviderUnavailableException('Connect a TikTok account before searching for music.');
        }

        // Upload-Post has no "get track by id" endpoint (confirmed against
        // https://docs.upload-post.com/api/reference) — the closest available lookup is
        // searching the cached trending charts and matching the vendor's own `id`, the same
        // catalog `search()` above already draws from.
        $track = $this->fetchTracks($account, [])->first(fn (array $track): bool => (string) ($track['id'] ?? '') === $id);

        return $track ? $this->toMusicTrack($track, $platform) : null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchTracks(SocialAccount $account, array $params): Collection
    {
        $username = $account->external_account_id ?? $account->external_profile_id ?? (string) $account->id;

        Log::info('Searching Upload-Post TikTok music catalog', [
            'social_account_id' => $account->id,
            'params' => $params,
        ]);

        try {
            $response = $this->client($account)->get('/uploadposts/tiktok/music/search', [
                'profile' => $username,
                ...$params,
            ]);
        } catch (Throwable $e) {
            Log::error('Upload-Post music search request failed', [
                'social_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            throw new MusicProviderUnavailableException('Unable to reach the TikTok music catalog right now.', previous: $e);
        }

        if (! $response->successful()) {
            Log::warning('Upload-Post music search rejected', [
                'social_account_id' => $account->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $vendorMessage = $response->json('message');

            throw new MusicProviderUnavailableException(
                $vendorMessage && $response->json('error_code') === 'tiktok_reconnect_required'
                    ? 'Your TikTok connection needs to be re-authenticated before you can search for music.'
                    : ($vendorMessage ?: 'The TikTok music catalog rejected the request.'),
            );
        }

        return collect($response->json('tracks') ?? []);
    }

    /**
     * @param  array<string, mixed>  $track
     */
    private function toMusicTrack(array $track, Platform $platform): MusicTrack
    {
        return new MusicTrack(
            id: (string) ($track['id'] ?? ''),
            name: (string) ($track['title'] ?? ''),
            artist: $track['artist'] ?? null,
            previewUrl: $track['preview_url'] ?? null,
            platform: $platform,
        );
    }

    /**
     * Resolves the current authenticated user's organization the same way every controller in
     * this app does (`.junie/modules/settings.md`), then its first connected TikTok account —
     * the vendor's `profile` param must belong to a TikTok account Upload-Post can actually query
     * charts for.
     */
    private function connectedTikTokAccount(): ?SocialAccount
    {
        $organization = Auth::user()?->organization;

        if ($organization === null) {
            return null;
        }

        return SocialAccount::query()
            ->where('org_id', $organization->id)
            ->where('platform', Platform::TikTok)
            ->where('connection_status', ConnectionStatus::Connected)
            ->first();
    }

    private function client(SocialAccount $account): PendingRequest
    {
        $apiKey = $account->organization?->upload_post_key ?? '';

        return Http::baseUrl($this->baseUrl())
            ->withHeaders(['Authorization' => 'Apikey '.$apiKey])
            ->acceptJson();
    }

    private function baseUrl(): string
    {
        return $this->baseUrl ?? config('services.upload_post.base_url', 'https://api.upload-post.com/api');
    }
}
