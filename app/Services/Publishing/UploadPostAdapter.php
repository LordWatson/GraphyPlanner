<?php

namespace App\Services\Publishing;

use App\Contracts\PublishAdapter;
use App\Enums\AssetType;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Support\Publishing\TargetResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * PublishAdapter implementation for Upload-Post (spec §7/§7.1). Each organization's
 * `upload_post_key` (Step 0.15) is used as the vendor API key, so every request is authenticated
 * per-org rather than via a shared app-wide credential.
 *
 * `connectAccount()`/`handleCallback()` implement the Step 1.3 account connect flow against
 * Upload-Post's JWT-based hosted linking page. `cancel()`/`health()` remain interim/partial —
 * real cancellation lands in Step 1.5, and `health()`'s use in the token-expiry integration lands
 * in Step 1.6.
 */
class UploadPostAdapter implements PublishAdapter
{
    public function __construct(private readonly ?string $baseUrl = null) {}

    /**
     * Ask Upload-Post to generate a hosted "connect" link for this account (spec §7, Step 1.3).
     * The link's `redirect_url` carries the account id so `handleCallback()` can identify which
     * SocialAccount to update once the user finishes linking on Upload-Post's side.
     */
    public function connectAccount(SocialAccount $account): string
    {
        $username = $account->external_account_id ?? $account->external_profile_id ?? (string) $account->id;

        $this->ensureProfileExists($account, $username);

        Log::info('Requesting Upload-Post connect link', ['social_account_id' => $account->id]);

        try {
            $response = $this->client($account)->post('/uploadposts/users/generate-jwt', [
                'username' => $username,
                'redirect_url' => route('social-accounts.callback', ['social_account_id' => $account->id]),
            ]);
        } catch (Throwable $e) {
            Log::error('Upload-Post connect request failed', [
                'social_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Unable to start the Upload-Post connect flow: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            Log::warning('Upload-Post connect request rejected', [
                'social_account_id' => $account->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('Upload-Post rejected the connect request: '.($response->json('error') ?? $response->body()));
        }

        return (string) ($response->json('access_url') ?? $response->json('url'));
    }

    /**
     * Upload-Post's `generate-jwt` endpoint only issues a linking token for a profile that
     * already exists — it doesn't implicitly create one, so `connectAccount()` must create the
     * profile via `POST /uploadposts/users` first (spec §7, Step 1.3 bugfix). A response
     * indicating the profile already exists is treated as success (idempotent).
     */
    private function ensureProfileExists(SocialAccount $account, string $username): void
    {
        try {
            $response = $this->client($account)->post('/uploadposts/users', [
                'username' => $username,
            ]);
        } catch (Throwable $e) {
            Log::error('Upload-Post profile creation request failed', [
                'social_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Unable to start the Upload-Post connect flow: '.$e->getMessage(), previous: $e);
        }

        if ($response->successful()) {
            return;
        }

        $alreadyExists = str_contains(strtolower((string) $response->json('error_code')), 'exist')
            || str_contains(strtolower((string) ($response->json('error') ?? $response->body())), 'already exist');

        if ($alreadyExists) {
            return;
        }

        Log::warning('Upload-Post profile creation rejected', [
            'social_account_id' => $account->id,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new RuntimeException('Upload-Post rejected the profile creation request: '.($response->json('error') ?? $response->body()));
    }

    /**
     * Persist the externalProfileId and flip connectionStatus once Upload-Post redirects the user
     * back to `redirect_url` (spec §7, Step 1.3). The account id travels round-trip via the query
     * string set in connectAccount(), since Upload-Post's callback query shape doesn't carry it.
     *
     * @param  array<string, mixed>  $query
     */
    public function handleCallback(array $query): void
    {
        $accountId = $query['social_account_id'] ?? null;

        if (! $accountId) {
            Log::warning('Upload-Post callback missing social_account_id', ['query' => $query]);

            return;
        }

        $account = SocialAccount::find($accountId);

        if (! $account) {
            Log::warning('Upload-Post callback referenced an unknown social account', ['social_account_id' => $accountId]);

            return;
        }

        $account->update([
            'external_profile_id' => $query['profile'] ?? $query['username'] ?? $account->external_profile_id,
            'connection_status' => ConnectionStatus::Connected,
            'connected_at' => now(),
        ]);

        Log::info('Upload-Post account connected', [
            'social_account_id' => $account->id,
            'external_profile_id' => $account->external_profile_id,
        ]);
    }

    /**
     * @param  Collection<int, SocialAccount>  $targets
     * @return Collection<int, TargetResult>
     */
    public function publish(Post $post, Collection $targets): Collection
    {
        return $targets->map(fn (SocialAccount $account): TargetResult => $this->publishToTarget($post, $account));
    }

    public function cancel(string $externalPostId): void
    {
        // No SocialAccount/org context is available from this signature to authenticate the
        // request — real cancellation is wired up alongside the schedule endpoint (Step 1.4/1.5).
        Log::info('Upload-Post cancel requested (not yet implemented)', ['external_post_id' => $externalPostId]);
    }

    public function health(SocialAccount $account): bool
    {
        try {
            return $this->client($account)->get('/uploadposts/users')->successful();
        } catch (Throwable $e) {
            Log::warning('Upload-Post health check failed', [
                'social_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function publishToTarget(Post $post, SocialAccount $account): TargetResult
    {
        [$fields, $sent, $skipped] = $this->buildFields($post, $account);

        Log::info('Publishing post via Upload-Post', [
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform' => $account->platform->value,
        ]);

        try {
            $response = $this->client($account)->post('/uploadposts/schedule', $fields);
        } catch (Throwable $e) {
            Log::error('Upload-Post publish request failed', [
                'post_id' => $post->id,
                'social_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return new TargetResult(
                accountId: $account->id,
                ok: false,
                error: $e->getMessage(),
                sentFields: $sent,
                skippedFields: $skipped,
            );
        }

        if (! $response->successful()) {
            Log::warning('Upload-Post publish rejected', [
                'post_id' => $post->id,
                'social_account_id' => $account->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return new TargetResult(
                accountId: $account->id,
                ok: false,
                error: $response->json('error') ?? $response->body(),
                sentFields: $sent,
                skippedFields: $skipped,
            );
        }

        return new TargetResult(
            accountId: $account->id,
            ok: true,
            externalPostId: (string) ($response->json('request_id') ?? $response->json('id')),
            sentFields: $sent,
            skippedFields: $skipped,
        );
    }

    /**
     * Build the Upload-Post request payload for a single target, tracking which fields were
     * actually sent vs. skipped (missing source data) per spec §7's `TargetResult` shape.
     *
     * @return array{0: array<string, mixed>, 1: array<int, string>, 2: array<int, string>}
     */
    private function buildFields(Post $post, SocialAccount $account): array
    {
        $sent = [];
        $skipped = [];

        $fields = [
            'user' => $account->external_account_id ?? $account->external_profile_id ?? (string) $account->id,
            'title' => (string) $post->master_caption,
            'platform' => [$account->platform->value],
        ];
        $sent = [...$sent, 'user', 'title', 'platform'];

        /** @var PostTarget|null $target */
        $target = $post->targets->firstWhere('social_account_id', $account->id);

        if ($target?->scheduled_at_utc) {
            $fields['scheduled_date'] = $target->scheduled_at_utc->toIso8601String();
            $sent[] = 'scheduled_date';
        } else {
            $skipped[] = 'scheduled_date';
        }

        $mediaUrls = $post->assets->pluck('url')->filter()->values()->all();
        if ($mediaUrls !== []) {
            $fields['media_urls'] = $mediaUrls;
            $sent[] = 'media_urls';
        } else {
            $skipped[] = 'media_urls';
        }

        if (! empty($post->hashtags)) {
            $fields['hashtags'] = $post->hashtags;
            $sent[] = 'hashtags';
        } else {
            $skipped[] = 'hashtags';
        }

        match ($account->platform) {
            Platform::Instagram => $this->applyInstagramFields($post, $fields, $sent, $skipped),
            Platform::TikTok => $this->applyTikTokFields($post, $fields, $sent, $skipped),
            // LinkedIn/Facebook: caption + media + schedule only, per spec §7.1.
            Platform::LinkedIn, Platform::Facebook => null,
        };

        return [$fields, $sent, $skipped];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $sent
     * @param  array<int, string>  $skipped
     */
    private function applyInstagramFields(Post $post, array &$fields, array &$sent, array &$skipped): void
    {
        $locationId = $post->location['id'] ?? null;
        if ($locationId) {
            $fields['location_id'] = $locationId;
            $sent[] = 'location_id';
        } else {
            $skipped[] = 'location_id';
        }

        // media_type (REELS/IMAGE/STORIES) — STORIES isn't derivable from any existing
        // flag/field, so only REELS (video) / IMAGE (image) are inferred from the first
        // attached asset's type. See `.junie/modules/publishing.md` for the open question.
        $mediaType = match ($post->assets->first()?->type) {
            AssetType::Video => 'REELS',
            AssetType::Image => 'IMAGE',
            default => null,
        };
        if ($mediaType) {
            $fields['media_type'] = $mediaType;
            $sent[] = 'media_type';
        } else {
            $skipped[] = 'media_type';
        }

        $audioId = $post->music['id'] ?? null;
        $audioName = $post->music['name'] ?? null;
        if ($audioId) {
            // A real vendor audio id is available — no need for the audio_name fallback.
            $fields['audio_id'] = $audioId;
            $sent[] = 'audio_id';
        } elseif ($audioName) {
            $fields['audio_name'] = $audioName;
            $sent[] = 'audio_name';
        } else {
            $skipped[] = 'audio_name';
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $sent
     * @param  array<int, string>  $skipped
     */
    private function applyTikTokFields(Post $post, array &$fields, array &$sent, array &$skipped): void
    {
        $musicId = $post->music['id'] ?? null;
        if ($musicId) {
            $fields['tiktok_music_id'] = $musicId;
            $sent[] = 'tiktok_music_id';
        } else {
            $skipped[] = 'tiktok_music_id';
        }

        $locationName = $post->location['name'] ?? null;
        if ($locationName) {
            $fields['location'] = $locationName;
            $sent[] = 'location';
        } else {
            $skipped[] = 'location';
        }

        // Post has no `is_ai_generated` flag yet — always skipped until the model gains one.
        // See `.junie/modules/publishing.md` for the open question.
        $skipped[] = 'tiktok_is_ai_generated';
    }

    private function client(SocialAccount $account): PendingRequest
    {
        $apiKey = $account->organization?->upload_post_key ?? '';

        // Upload-Post's API authenticates via an `Apikey` scheme, not the standard OAuth
        // `Bearer` scheme, so `withToken()` (which always sends `Bearer <token>`) can't be used
        // here — see https://docs.upload-post.com/api/reference.
        return Http::baseUrl($this->baseUrl())
            ->withHeaders(['Authorization' => 'Apikey '.$apiKey])
            ->acceptJson();
    }

    private function baseUrl(): string
    {
        return $this->baseUrl ?? config('services.upload_post.base_url', 'https://api.upload-post.com/api');
    }
}
