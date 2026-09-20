<?php

namespace App\Http\Controllers;

use App\Actions\Publishing\SyncPublishStatusAction;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Step 1.5 — receives Upload-Post's publish-outcome webhook and syncs it onto the matching
 * `PostTarget` (identified by the `job_id` recorded when `PublishPostJob` first accepted the
 * target, matched against the payload's `platform` since one `job_id` fans out to one event per
 * platform — see https://docs.upload-post.com/api/webhooks). This is the primary sync path;
 * `PollPendingPublishStatusesCommand` is the fallback for when a webhook never arrives.
 *
 * Only `upload_completed` is handled here; other event types (`social_account_connected` /
 * `_disconnected` / `_reauth_required`) are account-health notifications unrelated to a specific
 * publish and are acknowledged without action — see `.junie/modules/publishing.md`.
 */
class UploadPostWebhookController extends Controller
{
    /**
     * Deliveries this old are rejected outright (replay protection), per the vendor's docs.
     */
    private const MAX_TIMESTAMP_SKEW_SECONDS = 300;

    public function handle(Request $request): JsonResponse
    {
        $event = (string) $request->header('X-Upload-Post-Event', $request->input('event'));

        if ($event !== 'upload_completed') {
            Log::info('Upload-Post webhook: ignoring non-publish event', ['event' => $event]);

            return response()->json(['received' => true]);
        }

        $jobId = (string) $request->input('job_id');
        $platform = strtolower((string) $request->input('platform'));

        if ($jobId === '' || $platform === '') {
            Log::warning('Upload-Post webhook: payload missing job_id/platform', $request->all());

            return response()->json(['message' => 'Missing job_id or platform'], 422);
        }

        $target = PostTarget::query()
            ->where('external_post_id', $jobId)
            ->where('status', PostTargetStatus::Pending)
            ->whereHas('socialAccount', fn ($query) => $query->where('platform', Platform::tryFrom($platform)))
            ->first();

        if (! $target) {
            // Either already resolved (duplicate/late webhook) or from a target we don't know
            // about — acknowledge with 200 either way so the vendor doesn't retry forever.
            Log::info('Upload-Post webhook: no pending target for job_id/platform', [
                'job_id' => $jobId,
                'platform' => $platform,
            ]);

            return response()->json(['received' => true]);
        }

        if (! $this->hasValidSignature($request, $target)) {
            Log::warning('Upload-Post webhook: invalid or missing signature', [
                'job_id' => $jobId,
                'platform' => $platform,
            ]);

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $result = (array) $request->input('result', []);
        $ok = (bool) ($result['success'] ?? false);

        app(SyncPublishStatusAction::class)(
            $target,
            $ok,
            $ok ? null : ((string) ($result['error'] ?? 'Upload-Post reported a failed publish.')),
        );

        return response()->json(['received' => true]);
    }

    /**
     * Verifies `X-Upload-Post-Signature: sha256=<hex>` against
     * `HMAC_SHA256(webhook_secret, "{timestamp}.{raw body}")`, where `webhook_secret` is the
     * resolved target's organization's `upload_post_webhook_secret` (Upload-Post issues one
     * secret per account, not a single global one — see
     * https://docs.upload-post.com/api/webhooks#security). Also rejects requests with a missing
     * or stale `X-Upload-Post-Timestamp` (replay protection, per the vendor's docs).
     *
     * When the resolved organization has no webhook secret configured yet (e.g. it hasn't been
     * copied from the Upload-Post dashboard), verification is skipped with a warning rather than
     * rejecting every request outright.
     */
    private function hasValidSignature(Request $request, PostTarget $target): bool
    {
        $secret = (string) ($target->socialAccount?->organization?->upload_post_webhook_secret ?? '');

        if ($secret === '') {
            Log::warning('Upload-Post webhook: no webhook secret configured for this organization, skipping signature verification', [
                'post_target_id' => $target->id,
            ]);

            return true;
        }

        $timestamp = $request->header('X-Upload-Post-Timestamp');

        if (! is_numeric($timestamp) || abs(time() - (int) $timestamp) > self::MAX_TIMESTAMP_SKEW_SECONDS) {
            return false;
        }

        $header = (string) $request->header('X-Upload-Post-Signature', '');

        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $signature = substr($header, strlen('sha256='));
        $expected = hash_hmac('sha256', "{$timestamp}.{$request->getContent()}", $secret);

        return hash_equals($expected, $signature);
    }
}
