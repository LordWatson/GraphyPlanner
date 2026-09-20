<?php

namespace App\Services\Publishing;

use App\Contracts\PublishAdapter;
use App\Enums\AssetType;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Models\Asset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Support\Publishing\TargetResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    /**
     * Step 1.5 poll fallback: asks Upload-Post for the current outcome of a previously accepted
     * publish, in case the webhook never arrives. Per the real "Upload Status" endpoint
     * (`GET /uploadposts/status?request_id=...|job_id=...`, see
     * https://docs.upload-post.com/api/upload-status), `$externalPostId` may be either kind of
     * id depending on how the original publish was submitted (`request_id` for `async_upload`,
     * `job_id` for scheduled posts) — both query params are sent so either id resolves correctly
     * without the caller having to know which flavor it captured.
     *
     * The vendor's top-level `status` only tracks aggregate progress across every platform in
     * the job, so when the response's per-platform `results[]` includes an entry for this
     * account's platform, that entry's `success`/`message` is used instead — otherwise the
     * top-level `status`/`message` is the best available signal. Returns null (still
     * processing/unknown, including `pending`/`queued`/`processing`/`in_progress`/`not_found`)
     * rather than a failed `TargetResult` whenever the outcome can't be determined, so a poller
     * never mistakes "don't know yet" for "failed".
     */
    public function checkStatus(SocialAccount $account, string $externalPostId): ?TargetResult
    {
        try {
            $response = $this->client($account)->get('/uploadposts/status', [
                'request_id' => $externalPostId,
                'job_id' => $externalPostId,
            ]);
        } catch (Throwable $e) {
            Log::warning('Upload-Post status check failed', [
                'social_account_id' => $account->id,
                'external_post_id' => $externalPostId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Upload-Post status check rejected', [
                'social_account_id' => $account->id,
                'external_post_id' => $externalPostId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $status = strtolower((string) ($response->json('status') ?? ''));

        if (! in_array($status, ['completed', 'failed'], true)) {
            // pending / queued / processing / in_progress / not_found (or an unrecognized
            // shape) — the vendor hasn't resolved it yet, try again on the next poll.
            return null;
        }

        $results = collect($response->json('results') ?? []);
        $platformResult = $results->first(fn ($result) => ($result['platform'] ?? null) === $account->platform->value);

        if ($platformResult !== null) {
            $ok = (bool) ($platformResult['success'] ?? false);

            return new TargetResult(
                accountId: $account->id,
                ok: $ok,
                externalPostId: $externalPostId,
                error: $ok ? null : ($platformResult['message'] ?? $response->json('message')),
            );
        }

        $ok = $status === 'completed';

        return new TargetResult(
            accountId: $account->id,
            ok: $ok,
            externalPostId: $externalPostId,
            error: $ok ? null : ($response->json('message') ?? $response->body()),
        );
    }

    private function publishToTarget(Post $post, SocialAccount $account): TargetResult
    {
        [$fields, $sent, $skipped] = $this->buildFields($post, $account);
        $endpoint = $this->resolveUploadEndpoint($post);

        Log::info('Publishing post via Upload-Post', [
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform' => $account->platform->value,
            'endpoint' => $endpoint,
        ]);

        try {
            // The upload/upload_photos endpoints only recognize the vendor's documented fields
            // (e.g. `user`, `platform[]`, `photos[]`) when sent as multipart/form-data — sending
            // them as JSON (the Http client's default) silently drops every field and the vendor
            // replies with a generic "Username required in form data" error. upload_text has no
            // file-ish fields and is documented as JSON, so it's left untouched.
            if ($endpoint === '/upload_text') {
                $response = $this->client($account)->post($endpoint, $fields);
            } else {
                $request = $this->client($account)->asMultipart();

                // Assets stored on a disk we control are attached as real multipart files rather
                // than sent as a `video`/`photos[]` URL — the app's own APP_URL can be a
                // local-only dev domain (e.g. `*.test`) that Upload-Post's servers can never
                // reach, which the vendor rejects with a generic "required"/"not allowed" error
                // even though a URL was technically present. Assets with no local disk/path (e.g.
                // externally-linked) still fall back to sending their URL as before.
                $this->attachLocalMediaFiles($request, $post, $fields);

                $response = $request->post($endpoint, $this->toMultipartFields($fields));
            }
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
            // A scheduled post (the `scheduled_date` field above) returns `job_id`; an immediate
            // upload returns `request_id` — either is accepted back by the real status endpoint
            // (see `checkStatus()`).
            externalPostId: (string) ($response->json('job_id') ?? $response->json('request_id') ?? $response->json('id')),
            sentFields: $sent,
            skippedFields: $skipped,
        );
    }

    /**
     * Upload-Post has no single generic "publish" endpoint — the request must be sent to the
     * media-appropriate endpoint (video / photos / text-only), see
     * https://docs.upload-post.com/api/reference. A post with at least one video asset uses the
     * video endpoint (only the first video is sent, matching that endpoint's single-file
     * contract); image-only posts use the photos endpoint (all image URLs, as a carousel);
     * posts with no assets fall back to the text-only endpoint.
     */
    private function resolveUploadEndpoint(Post $post): string
    {
        if ($post->assets->contains(fn ($asset) => $asset->type === AssetType::Video)) {
            return '/upload';
        }

        if ($post->assets->contains(fn ($asset) => $asset->type === AssetType::Image)) {
            return '/upload_photos';
        }

        return '/upload_text';
    }

    /**
     * Attach any locally-stored video/image assets to the request as real multipart files
     * (rather than a URL Upload-Post would have to fetch itself), removing the corresponding
     * `video`/`photos` entry from `$fields` so it isn't also sent as a (redundant, and possibly
     * unreachable) URL. Assets with no local disk/path — e.g. genuinely external links — are left
     * in `$fields` to be sent as a URL, unchanged.
     *
     * @param  array<string, mixed>  $fields
     */
    private function attachLocalMediaFiles(PendingRequest $request, Post $post, array &$fields): void
    {
        /** @var Asset|null $videoAsset */
        $videoAsset = $post->assets->firstWhere('type', AssetType::Video);

        if ($videoAsset && $this->hasLocalFile($videoAsset)) {
            $request->attach('video', Storage::disk($videoAsset->disk)->get($videoAsset->path), $this->assetFilename($videoAsset));
            unset($fields['video']);
        }

        /** @var Collection<int, Asset> $imageAssets */
        $imageAssets = $post->assets->where('type', AssetType::Image)->values();

        if ($imageAssets->isEmpty()) {
            return;
        }

        $localImages = $imageAssets->filter(fn (Asset $asset): bool => $this->hasLocalFile($asset));
        $remoteImageUrls = $imageAssets->reject(fn (Asset $asset): bool => $this->hasLocalFile($asset))
            ->pluck('url')->filter()->values()->all();

        foreach ($localImages as $asset) {
            $request->attach('photos[]', Storage::disk($asset->disk)->get($asset->path), $this->assetFilename($asset));
        }

        if ($remoteImageUrls !== []) {
            $fields['photos'] = $remoteImageUrls;
        } else {
            unset($fields['photos']);
        }
    }

    private function hasLocalFile(Asset $asset): bool
    {
        return filled($asset->disk) && filled($asset->path) && Storage::disk($asset->disk)->exists($asset->path);
    }

    private function assetFilename(Asset $asset): string
    {
        return $asset->original_filename ?: basename((string) $asset->path);
    }

    /**
     * Convert the associative `buildFields()` payload into the list-of-parts shape Upload-Post's
     * multipart endpoints expect: array values become repeated `name[]` parts (e.g. `platform[]`,
     * `photos[]`), matching the vendor's documented `-F` examples.
     *
     * @param  array<string, mixed>  $fields
     * @return array<int, array{name: string, contents: string}>
     */
    private function toMultipartFields(array $fields): array
    {
        $parts = [];

        foreach ($fields as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $parts[] = ['name' => "{$name}[]", 'contents' => (string) $item];
                }

                continue;
            }

            $parts[] = ['name' => $name, 'contents' => (string) $value];
        }

        return $parts;
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

        $videoUrl = $post->assets->firstWhere('type', AssetType::Video)?->url;
        $imageUrls = $post->assets->where('type', AssetType::Image)->pluck('url')->filter()->values()->all();

        if ($videoUrl) {
            $fields['video'] = $videoUrl;
            $sent[] = 'video';
        } elseif ($imageUrls !== []) {
            $fields['photos'] = $imageUrls;
            $sent[] = 'photos';
        } else {
            $skipped[] = 'media';
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
