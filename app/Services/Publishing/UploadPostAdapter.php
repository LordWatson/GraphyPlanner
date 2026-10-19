<?php

namespace App\Services\Publishing;

use App\Contracts\AssetStorage;
use App\Contracts\PublishAdapter;
use App\Enums\AssetType;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Models\Asset;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\Media\PublishImageCropper;
use App\Support\Publishing\CancelResult;
use App\Support\Publishing\CaptionComposer;
use App\Support\Publishing\FormatSpec;
use App\Support\Publishing\PlatformSpec;
use App\Support\Publishing\TargetResult;
use App\Support\Publishing\VendorHttp;
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
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly PlatformRolloutGate $rolloutGate = new PlatformRolloutGate,
        private readonly PublishImageCropper $cropper = new PublishImageCropper,
    ) {}

    /**
     * Ask Upload-Post to generate a hosted "connect" link for this account (spec §7, Step 1.3).
     * The link's `redirect_url` carries the account id so `handleCallback()` can identify which
     * SocialAccount to update once the user finishes linking on Upload-Post's side.
     */
    public function connectAccount(SocialAccount $account, ?string $state = null): string
    {
        $username = $account->external_account_id ?? $account->external_profile_id ?? (string) $account->id;

        $this->ensureProfileExists($account, $username);

        Log::info('Requesting Upload-Post connect link', ['social_account_id' => $account->id]);

        try {
            $spec = $account->platform->spec();
            $response = $this->client($account)->post('/uploadposts/users/generate-jwt', array_filter([
                'username' => $username,
                'redirect_url' => route('social-accounts.callback', array_filter([
                    'social_account_id' => $account->id,
                    'state' => $state,
                ])),
                // Step M-2.6: show only this account's network on the hosted page, where the vendor
                // supports narrowing it (the registry's `hosted_connect`).
                'platforms' => $spec->hostedConnect ? [$spec->vendor] : null,
            ]));
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

    /**
     * Step I-3.6 / M-2.5: `DELETE /uploadposts/schedule/{job_id}`
     * (https://docs.upload-post.com/api/schedule-posts). Only a 2xx with `success` counts as
     * cancelled. A 404 means the vendor no longer holds the job — it may already have published —
     * and a 409 means it's publishing now; both are reported as too late, never as success.
     */
    public function cancel(PostTarget $target): CancelResult
    {
        $jobId = $target->external_post_id;
        $account = $target->socialAccount;

        if (blank($jobId)) {
            return CancelResult::cancelled();
        }

        if ($account === null) {
            return CancelResult::failed('This channel’s account no longer exists.');
        }

        Log::info('Cancelling Upload-Post scheduled publish', ['post_id' => $target->post_id, 'post_target_id' => $target->id]);

        try {
            $response = $this->client($account)->delete('/uploadposts/schedule/'.rawurlencode((string) $jobId));
        } catch (Throwable $e) {
            Log::warning('Upload-Post cancel request failed', ['post_target_id' => $target->id, 'error' => $e->getMessage()]);

            return CancelResult::failed('Couldn’t reach the publishing service to cancel: '.$e->getMessage());
        }

        if ($response->successful() && $response->json('success') !== false) {
            Log::info('Upload-Post scheduled publish cancelled', [
                'post_id' => $target->post_id,
                'post_target_id' => $target->id,
                'credits_refunded' => $response->json('credits_refunded'),
            ]);

            return CancelResult::cancelled();
        }

        Log::warning('Upload-Post cancel rejected', [
            'post_target_id' => $target->id,
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 500),
        ]);

        return in_array($response->status(), [404, 409], true)
            ? CancelResult::failed('It’s already publishing (or has gone out) on the platform.', tooLate: true)
            : CancelResult::failed((string) ($response->json('error') ?? 'The publishing service refused to cancel it.'));
    }

    /**
     * Step I-3.6 / M-2.5: `POST /uploadposts/users/notifications` on the vendor's app host
     * (https://docs.upload-post.com/api/webhooks) with the webhook enabled for every event. The
     * signing secret comes back in the response (or, if not, from a follow-up GET). The secret is
     * returned, never logged.
     */
    public function registerWebhook(Organization $organization, string $url): ?string
    {
        $request = VendorHttp::forReads(
            Http::baseUrl((string) config('services.upload_post.app_base_url', 'https://app.upload-post.com/api'))
                ->withHeaders(['Authorization' => 'Apikey '.($organization->upload_post_key ?? '')])
                ->acceptJson(),
        );

        try {
            $response = $request->post('/uploadposts/users/notifications', [
                'channels' => ['webhook' => true],
                'webhook_url' => $url,
                'webhook_events' => [
                    'upload_completed' => true,
                    'social_account_connected' => true,
                    'social_account_disconnected' => true,
                    'social_account_reauth_required' => true,
                ],
            ]);

            $secret = $response->json('notifications.webhook_secret');

            if ($response->successful() && blank($secret)) {
                $current = $request->get('/uploadposts/users/notifications');
                $secret = $current->json('notifications.webhook_secret') ?? $current->json('webhook_secret');
            }
        } catch (Throwable $e) {
            Log::warning('Upload-Post webhook registration failed', ['org_id' => $organization->id, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() || blank($secret)) {
            Log::warning('Upload-Post webhook registration rejected', ['org_id' => $organization->id, 'status' => $response->status()]);

            return null;
        }

        Log::info('Upload-Post webhook registered', ['org_id' => $organization->id]);

        return (string) $secret;
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
     * Step I-1.6: a read-only call authenticated with the organization's key — any 2xx means the
     * key is accepted.
     */
    public function verifyCredentials(Organization $organization): bool
    {
        try {
            return VendorHttp::forReads(
                Http::baseUrl($this->baseUrl())
                    ->withHeaders(['Authorization' => 'Apikey '.($organization->upload_post_key ?? '')])
                    ->acceptJson(),
            )->get('/uploadposts/users')->successful();
        } catch (Throwable $e) {
            Log::warning('Upload-Post credential check failed', ['org_id' => $organization->id, 'error' => $e->getMessage()]);

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
        // Step 1.7: platform rollout gate — never calls the vendor for a stage that hasn't been
        // enabled yet (Instagram feed -> Reels -> TikTok -> Facebook -> LinkedIn, in order).
        $stage = $this->rolloutGate->stageFor($post, $account);

        if (! $this->rolloutGate->stageEnabled($stage)) {
            Log::info('Upload-Post publish skipped: rollout stage not yet enabled', [
                'post_id' => $post->id,
                'social_account_id' => $account->id,
                'platform' => $account->platform->value,
                'rollout_stage' => $stage->value,
            ]);

            return new TargetResult(
                accountId: $account->id,
                ok: false,
                error: "Publishing to {$stage->label()} is not yet enabled (Step 1.7 rollout).",
            );
        }

        [$fields, $sent, $skipped] = $this->buildFields($post, $account);
        $endpoint = $this->resolveUploadEndpoint($post);
        $attemptId = $post->targets->firstWhere('social_account_id', $account->id)?->publish_attempt_id;

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
                $response = $this->publishClient($account, $attemptId)->post($endpoint, $fields);
            } else {
                $request = $this->publishClient($account, $attemptId)->asMultipart();

                // Assets stored on a disk we control are attached as real multipart files rather
                // than sent as a `video`/`photos[]` URL — the app's own APP_URL can be a
                // local-only dev domain (e.g. `*.test`) that Upload-Post's servers can never
                // reach, which the vendor rejects with a generic "required"/"not allowed" error
                // even though a URL was technically present. Assets with no local disk/path (e.g.
                // externally-linked) still fall back to sending their URL as before.
                $this->attachLocalMediaFiles($request, $post, $account, $fields);

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
     * Local images are cut to the post's format for this channel first (`PublishImageCropper`),
     * so what publishes matches the editor preview. Videos and remote images always go out as
     * uploaded, and so do AI-generated images bound for LinkedIn: LinkedIn has no API label and
     * reads the C2PA credentials embedded in the original, which re-encoding would strip.
     *
     * @param  array<string, mixed>  $fields
     */
    private function attachLocalMediaFiles(PendingRequest $request, Post $post, SocialAccount $account, array &$fields): void
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

        /** @var array<int, string> $bytes */
        $bytes = $localImages->mapWithKeys(fn (Asset $asset) => [
            $asset->id => (string) Storage::disk($asset->disk)->get($asset->path),
        ])->all();

        // A carousel only sends images when there's no video, so only then does the format apply.
        $target = $post->targets->firstWhere('social_account_id', $account->id);
        $target?->setRelation('post', $post)->setRelation('socialAccount', $account);
        $ratio = $videoAsset ? null : $this->cropper->targetRatio(
            $post,
            $account->platform,
            $this->imageSize($imageAssets->first(), $bytes),
            $target?->resolvedFormat(),
        );
        $keepAiOriginals = $account->platform->spec()->keepAiOriginals;

        foreach ($localImages as $asset) {
            $contents = $bytes[$asset->id];

            if ($ratio !== null && ! ($keepAiOriginals && $asset->is_ai_generated)) {
                $contents = $this->cropper->crop($asset, $contents, $ratio, $post) ?? $contents;
            }

            $request->attach('photos[]', $contents, $this->assetFilename($asset));
        }

        if ($ratio !== null && $remoteImageUrls !== []) {
            Log::info('Remote images publish uncropped', [
                'post_id' => $post->id,
                'social_account_id' => $account->id,
                'count' => count($remoteImageUrls),
            ]);
        }

        if ($remoteImageUrls !== []) {
            $fields['photos'] = $remoteImageUrls;
        } else {
            unset($fields['photos']);
        }
    }

    /**
     * An image's pixel size: read from its local bytes when available (the stored width/height
     * can predate EXIF rotation), else the stored dimensions, else unknown.
     *
     * @param  array<int, string>  $bytes
     * @return array{0: int, 1: int}|null
     */
    private function imageSize(?Asset $asset, array $bytes): ?array
    {
        if ($asset === null) {
            return null;
        }

        if (isset($bytes[$asset->id])) {
            return $this->cropper->dimensions($bytes[$asset->id]);
        }

        return $asset->width && $asset->height ? [(int) $asset->width, (int) $asset->height] : null;
    }

    /**
     * Step I-1.6: a private file's URL is signed for long enough (`assets.vendor_url_ttl_minutes`)
     * for the vendor to fetch it later; public disks and external links keep their URL.
     */
    private function vendorUrl(Asset $asset): ?string
    {
        if (blank($asset->disk) || blank($asset->path)) {
            return $asset->url;
        }

        return app(AssetStorage::class)->signedUrl(
            (string) $asset->disk,
            (string) $asset->path,
            (int) config('assets.vendor_url_ttl_minutes', 1440),
        );
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
     * Everything platform-specific comes from the capability registry (`config/platforms.php`):
     * the vendor platform value and field prefix, whether `description` is rendered, link and
     * first-comment support, alt text, the format's media type, and the cover fields.
     *
     * Step I-3.1 / M-2.2: the caption is the *target's* — its override or the master — composed
     * with its hashtags and link exactly as the checklist counted it (`CaptionComposer`). Upload-Post
     * has no working `hashtags` parameter, so tags travel as literal `#tag` text in the caption
     * (below the dot-dash spacer where the platform folds captions). The generic `title` carries
     * the same text, and so does the platform's own `{prefix}_title`, which some platforms
     * (Instagram) render instead of `title`/`description`.
     *
     * @return array{0: array<string, mixed>, 1: array<int, string>, 2: array<int, string>}
     */
    private function buildFields(Post $post, SocialAccount $account): array
    {
        $sent = [];
        $skipped = [];
        $spec = $account->platform->spec();

        /** @var PostTarget|null $target */
        $target = $post->targets->firstWhere('social_account_id', $account->id);
        $target?->setRelation('post', $post)->setRelation('socialAccount', $account);

        $format = $target?->resolvedFormat()
            ?? app(PlatformCapabilities::class)->resolveFormat($account->platform, null, $post->mediaKinds());
        $caption = $target?->effectiveCaption() ?? (string) $post->master_caption;
        $hashtags = $target?->effectiveHashtags() ?? array_values($post->hashtags ?? []);
        $link = $target?->effectiveLink() ?? (filled($post->link_url) ? $post->link_url : null);
        $published = CaptionComposer::compose($spec, $caption, $hashtags, $link);

        $fields = [
            'user' => $account->external_account_id ?? $account->external_profile_id ?? (string) $account->id,
            'title' => $published,
            'platform' => [$spec->vendor],
        ];
        $sent = [...$sent, 'user', 'title', 'platform'];

        $fields[$spec->vendorField('title')] = $published;
        $sent[] = $spec->vendorField('title');

        if ($spec->vendorDescription) {
            $fields['description'] = $published;
            $sent[] = 'description';
        }

        // Step I-1.3: only a future time is a schedule — a retried or past-due target is sent for
        // immediate publishing instead of with a `scheduled_date` the vendor would reject.
        if ($target?->scheduled_at_utc && $target->scheduled_at_utc->isAfter(now()->addMinute())) {
            $fields['scheduled_date'] = $target->scheduled_at_utc->toIso8601String();
            $sent[] = 'scheduled_date';
        } else {
            $skipped[] = 'scheduled_date';
        }

        $media = $post->publishableMedia();
        $videoAsset = $media->first(fn (Asset $asset) => $asset->type === AssetType::Video);

        if ($videoAsset !== null && ($videoUrl = $this->vendorUrl($videoAsset))) {
            $fields['video'] = $videoUrl;
            $sent[] = 'video';
        } elseif ($videoAsset === null && ($imageUrls = $media->map(fn (Asset $asset) => $this->vendorUrl($asset))->filter()->values()->all()) !== []) {
            $fields['photos'] = $imageUrls;
            $sent[] = 'photos';
        } else {
            $skipped[] = 'media';
        }

        // Kept for compatibility; the vendor ignores it (tags are in the caption, see above).
        if ($hashtags !== [] && $spec->hashtags) {
            $fields['hashtags'] = $hashtags;
            $sent[] = 'hashtags';
        } else {
            $skipped[] = 'hashtags';
        }

        $firstComment = $target?->effectiveFirstComment() ?? (filled($post->first_comment) ? trim((string) $post->first_comment) : null);

        if ($firstComment !== null && $spec->firstComment) {
            $fields[$spec->vendorField('first_comment')] = $firstComment;
            $sent[] = $spec->vendorField('first_comment');
        } else {
            $skipped[] = 'first_comment';
        }

        if ($link !== null && $spec->linkField !== null) {
            $fields[$spec->linkField] = $link;
            $sent[] = $spec->linkField;
        }

        $this->applyAltText($spec, $videoAsset === null ? array_values($media->all()) : [], $fields, $sent, $skipped);
        $this->applyFormat($format, $videoAsset !== null, $fields, $sent, $skipped);
        $this->applyCover($spec, $post, $videoAsset, $fields, $sent);

        // Step 16.13 AI disclosure: Upload-Post's `is_ai_generated` alias maps to each platform's
        // native label (TikTok `is_aigc`, Instagram, Facebook Reels, YouTube synthetic media).
        // LinkedIn has no API label; it reads the C2PA credentials embedded in the vendor
        // original, which we never re-encode (`keep_ai_originals`). See
        // https://docs.upload-post.com/guides/ai-content-labeling and publishing.md.
        if ($post->hasAiGeneratedMedia()) {
            $fields['is_ai_generated'] = 'true';
            $sent[] = 'is_ai_generated';
        } else {
            $skipped[] = 'is_ai_generated';
        }

        match ($account->platform) {
            Platform::Instagram => $this->applyInstagramFields($post, $fields, $sent, $skipped),
            Platform::TikTok => $this->applyTikTokFields($post, $fields, $sent, $skipped),
            default => $this->applyPlatformExtras($spec, $account, $caption, $link, $fields, $sent, $skipped),
        };

        return [$fields, $sent, $skipped];
    }

    /**
     * Alt text per image, in publish order, for platforms that accept it (`{prefix}_alt_text`, a
     * JSON list). Only sent when at least one image has some.
     *
     * @param  list<Asset>  $images
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $sent
     * @param  array<int, string>  $skipped
     */
    private function applyAltText(PlatformSpec $spec, array $images, array &$fields, array &$sent, array &$skipped): void
    {
        $texts = array_map(fn (Asset $asset) => trim((string) ($asset->getRelationValue('pivot')->alt_text ?? '')), $images);

        if (! $spec->altText || collect($texts)->every(fn (string $text) => $text === '')) {
            $skipped[] = 'alt_text';

            return;
        }

        $field = $spec->vendorField('alt_text');
        $fields[$field] = (string) json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sent[] = $field;
    }

    /**
     * Step I-3.2 / M-2.3: the target's format as the vendor's media type (Instagram `media_type`
     * IMAGE / REELS / STORIES, Facebook `facebook_media_type` POSTS / VIDEO / REELS / STORIES).
     *
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $sent
     * @param  array<int, string>  $skipped
     */
    private function applyFormat(FormatSpec $format, bool $isVideo, array &$fields, array &$sent, array &$skipped): void
    {
        $mediaType = $isVideo && $format->vendorMediaTypeVideo !== null ? $format->vendorMediaTypeVideo : $format->vendorMediaType;

        if ($mediaType === null) {
            $skipped[] = 'media_type';

            return;
        }

        $fields[$format->vendorMediaTypeField] = $mediaType;
        $sent[] = $format->vendorMediaTypeField;
    }

    /**
     * Step I-3.2 / M-2.3: a video's cover image (as a URL the vendor fetches) and frame offset,
     * where the platform takes them.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $sent
     */
    private function applyCover(PlatformSpec $spec, Post $post, ?Asset $videoAsset, array &$fields, array &$sent): void
    {
        if ($videoAsset === null) {
            return;
        }

        if ($spec->coverField !== null && $post->cover_asset_id !== null) {
            $cover = $post->relationLoaded('coverAsset') ? $post->coverAsset : $post->coverAsset()->first();

            if ($cover !== null && ($url = $this->vendorUrl($cover))) {
                $fields[$spec->coverField] = $url;
                $sent[] = $spec->coverField;
            }
        }

        if ($spec->thumbOffsetField !== null && $post->thumb_offset_ms !== null) {
            $fields[$spec->thumbOffsetField] = (string) $post->thumb_offset_ms;
            $sent[] = $spec->thumbOffsetField;
        }
    }

    /**
     * Step I-3.4 / M-2.6: what the platforms beyond Instagram/TikTok need besides the common
     * fields — a short title (YouTube, Pinterest), the account's registry settings (Pinterest
     * board, Facebook Page, LinkedIn company page, Google location), a Google Business button
     * for the link.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $sent
     * @param  array<int, string>  $skipped
     */
    private function applyPlatformExtras(PlatformSpec $spec, SocialAccount $account, string $caption, ?string $link, array &$fields, array &$sent, array &$skipped): void
    {
        $settings = $account->platform_settings ?? [];

        // A short title from the caption's first line (YouTube and Pinterest show one).
        if ($spec->titleLimit !== null) {
            $title = self::shortTitle($caption, $spec->titleLimit);
            $fields[$spec->vendorField('title')] = $title !== '' ? $title : $spec->label;
        }

        // Each registry setting is keyed by the vendor field it's sent as.
        foreach (array_keys($spec->settings) as $setting) {
            if (filled($settings[$setting] ?? null)) {
                $fields[$setting] = (string) $settings[$setting];
                $sent[] = $setting;
            } else {
                $skipped[] = $setting;
            }
        }

        if ($spec->platform === Platform::GoogleBusiness) {
            $fields['gbp_topic_type'] = 'STANDARD';
            $sent[] = 'gbp_topic_type';

            if ($link !== null) {
                $fields['gbp_cta_type'] = 'LEARN_MORE';
                $sent[] = 'gbp_cta_type';
            }
        }

        if ($spec->platform === Platform::YouTube) {
            $fields['privacyStatus'] = 'public';
            $sent[] = 'privacyStatus';
        }
    }

    private static function shortTitle(string $caption, int $limit): string
    {
        $firstLine = trim((string) strtok(trim($caption), "\n"));

        return mb_strlen($firstLine) > $limit ? rtrim(mb_substr($firstLine, 0, $limit - 1)).'…' : $firstLine;
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
    }

    /**
     * Repeatable calls (status, health, connect) — retried on 429/5xx/connection errors.
     */
    private function client(SocialAccount $account): PendingRequest
    {
        return VendorHttp::forReads($this->baseRequest($account));
    }

    /**
     * The upload POST — retried on 429 only, with the target's attempt id as idempotency key.
     */
    private function publishClient(SocialAccount $account, ?string $attemptId): PendingRequest
    {
        return VendorHttp::forPublish($this->baseRequest($account), $attemptId);
    }

    private function baseRequest(SocialAccount $account): PendingRequest
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
