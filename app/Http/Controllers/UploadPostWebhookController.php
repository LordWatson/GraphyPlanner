<?php

namespace App\Http\Controllers;

use App\Actions\Publishing\SyncPublishStatusAction;
use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Step 1.5 — receives Upload-Post's publish-outcome webhook and syncs it onto the matching
 * `PostTarget` (identified by the `externalPostId` recorded when `PublishPostJob` first accepted
 * the target). This is the primary sync path; `PollPendingPublishStatusesCommand` is the fallback
 * for when a webhook never arrives.
 *
 * Upload-Post's real webhook payload/signature scheme wasn't available in this repo — the
 * expected shape (`request_id`/`id`, `status`, `error`) mirrors the fields already parsed from
 * its publish response in `UploadPostAdapter::publishToTarget()`, and the signature header name
 * is a best guess pending the real docs/sandbox (see `.junie/modules/publishing.md`).
 */
class UploadPostWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('Upload-Post webhook: invalid or missing signature');

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $externalPostId = (string) ($request->input('request_id') ?? $request->input('id') ?? $request->input('external_post_id'));
        $status = strtolower((string) $request->input('status'));
        $error = $request->input('error');

        if ($externalPostId === '') {
            Log::warning('Upload-Post webhook: payload missing an external post id', $request->all());

            return response()->json(['message' => 'Missing external post id'], 422);
        }

        $target = PostTarget::where('external_post_id', $externalPostId)
            ->where('status', PostTargetStatus::Pending)
            ->first();

        if (! $target) {
            // Either already resolved (duplicate/late webhook) or from a target we don't know
            // about — acknowledge with 200 either way so the vendor doesn't retry forever.
            Log::info('Upload-Post webhook: no pending target for external post id', [
                'external_post_id' => $externalPostId,
            ]);

            return response()->json(['received' => true]);
        }

        $ok = in_array($status, ['success', 'completed', 'published'], true);

        app(SyncPublishStatusAction::class)($target, $ok, $ok ? null : ($error ?: 'Upload-Post reported a failed publish.'));

        return response()->json(['received' => true]);
    }

    /**
     * Verifies the vendor's HMAC-SHA256 signature (`X-Upload-Post-Signature`, hex-encoded, over
     * the raw request body) against `UPLOAD_POST_WEBHOOK_SECRET`. When no secret is configured
     * (e.g. local development before a real one is issued), verification is skipped with a
     * warning rather than rejecting every request outright.
     */
    private function hasValidSignature(Request $request): bool
    {
        $secret = Config::string('services.upload_post.webhook_secret', '');

        if ($secret === '') {
            Log::warning('Upload-Post webhook: no webhook secret configured, skipping signature verification');

            return true;
        }

        $signature = (string) $request->header('X-Upload-Post-Signature', '');

        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
