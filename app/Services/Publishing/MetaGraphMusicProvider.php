<?php

namespace App\Services\Publishing;

use App\Contracts\MusicProvider;
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
 * MusicProvider implementation for Instagram audio search (Step 1.9.4), calling Meta's real
 * `GET /{version}/ig_audio` endpoint directly against `graph.facebook.com`
 * (https://developers.facebook.com/documentation/instagram-platform/content-publishing/audio-api)
 * — the endpoint Upload-Post has no equivalent for (Step 1.8.2's `UploadPostMusicProvider`
 * always returns empty for Instagram). Every other platform (TikTok) is delegated unchanged to
 * the wrapped `UploadPostMusicProvider`, so Step 1.8.2's TikTok behavior is untouched.
 */
class MetaGraphMusicProvider implements MusicProvider
{
    public function __construct(
        private readonly UploadPostMusicProvider $uploadPostMusicProvider,
    ) {}

    /**
     * @return Collection<int, MusicTrack>
     */
    public function search(string $query, Platform $platform): Collection
    {
        if ($platform !== Platform::Instagram) {
            return $this->uploadPostMusicProvider->search($query, $platform);
        }

        $account = $this->connectedInstagramAccount();

        Log::info('Searching Meta Graph API Instagram audio catalog', [
            'social_account_id' => $account->id,
        ]);

        $response = $this->request($account)->get('/ig_audio', [
            'audio_type' => 'music',
            'user_id' => $account->meta_instagram_user_id,
            'search_query' => $query,
        ]);

        $this->assertSuccessful($response, $account, 'search');

        return collect($response->json('data') ?? [])
            ->map(fn (array $audio): MusicTrack => $this->toMusicTrack($audio));
    }

    public function find(string $id, Platform $platform): ?MusicTrack
    {
        if ($platform !== Platform::Instagram) {
            return $this->uploadPostMusicProvider->find($id, $platform);
        }

        $account = $this->connectedInstagramAccount();

        Log::info('Fetching Meta Graph API Instagram audio metadata', [
            'social_account_id' => $account->id,
            'audio_id' => $id,
        ]);

        $response = $this->request($account)->get('/'.$id);

        if ($response->status() === 404) {
            return null;
        }

        $this->assertSuccessful($response, $account, 'find');

        return $this->toMusicTrack($response->json());
    }

    /**
     * @param  array<string, mixed>  $audio
     */
    private function toMusicTrack(array $audio): MusicTrack
    {
        return new MusicTrack(
            id: (string) ($audio['audio_id'] ?? ''),
            name: (string) ($audio['title'] ?? ''),
            artist: $audio['display_artist'] ?? null,
            previewUrl: $audio['download_url'] ?? $audio['on_platform_audio_preview_link'] ?? null,
            platform: Platform::Instagram,
        );
    }

    /**
     * Resolves the current authenticated user's organization the same way
     * `UploadPostMusicProvider::connectedTikTokAccount()` does, then the connected Instagram
     * account carrying the Meta audio-access grant from Step 1.9.3. Throws instead of returning
     * an empty collection so the editor UI can surface an actionable connect prompt (Step 1.9.5)
     * rather than an unexplained empty result.
     */
    private function connectedInstagramAccount(): SocialAccount
    {
        $organization = Auth::user()?->organization;

        $account = $organization === null ? null : SocialAccount::query()
            ->where('org_id', $organization->id)
            ->where('platform', Platform::Instagram)
            ->whereNotNull('meta_access_token')
            ->whereNotNull('meta_instagram_user_id')
            ->first();

        if ($account === null) {
            Log::warning('Meta Graph API Instagram audio search skipped: no connected Instagram Meta audio access for the current organization');

            throw new MusicProviderUnavailableException(
                'Connect Instagram via Facebook Login before searching for music.',
                errorCode: 'meta_not_connected',
            );
        }

        return $account;
    }

    private function assertSuccessful($response, SocialAccount $account, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        Log::warning('Meta Graph API Instagram audio request rejected', [
            'social_account_id' => $account->id,
            'action' => $action,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new MusicProviderUnavailableException(
            $response->json('error.message') ?: 'The Instagram audio catalog rejected the request.',
        );
    }

    private function request(SocialAccount $account): PendingRequest
    {
        try {
            return Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->withQueryParameters(['access_token' => $account->meta_access_token]);
        } catch (Throwable $e) {
            Log::error('Meta Graph API Instagram audio request failed to build', [
                'social_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            throw new MusicProviderUnavailableException('Unable to reach the Instagram audio catalog right now.', previous: $e);
        }
    }

    private function baseUrl(): string
    {
        $base = rtrim((string) config('services.facebook.graph_base_url', 'https://graph.facebook.com'), '/');
        $version = config('services.facebook.graph_version', 'v20.0');

        return $base.'/'.$version;
    }
}
