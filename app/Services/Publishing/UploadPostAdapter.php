<?php

namespace App\Services\Publishing;

use App\Contracts\PublishAdapter;
use App\Enums\AssetType;
use App\Enums\Platform;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Support\Publishing\TargetResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PublishAdapter implementation for Upload-Post (spec §7/§7.1), Step 1.2. Each organization's
 * `upload_post_key` (Step 0.15) is used as the vendor API key, so every request is authenticated
 * per-org rather than via a shared app-wide credential.
 *
 * `connectAccount()`/`handleCallback()`/`cancel()`/`health()` are interim stubs here — the real
 * connect flow, cancellation, and health/token-expiry checks land in Steps 1.3, 1.5, and 1.6
 * respectively. This step's scope is `publish()`'s per-platform field mapping only.
 */
class UploadPostAdapter implements PublishAdapter
{
    public function __construct(private readonly ?string $baseUrl = null) {}

    public function connectAccount(SocialAccount $account): string
    {
        // Interim: Upload-Post's hosted connect flow is wired up properly in Step 1.3.
        return sprintf('%s/uploadposts/users/generate-jwt', $this->baseUrl());
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function handleCallback(array $query): void
    {
        // Persisting externalProfileId/connectionStatus from the callback lands in Step 1.3.
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

        return Http::baseUrl($this->baseUrl())
            ->withToken($apiKey)
            ->acceptJson();
    }

    private function baseUrl(): string
    {
        return $this->baseUrl ?? config('services.upload_post.base_url', 'https://api.upload-post.com/api');
    }
}
