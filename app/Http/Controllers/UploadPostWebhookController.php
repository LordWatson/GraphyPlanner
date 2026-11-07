<?php

namespace App\Http\Controllers;

use App\Actions\Publishing\SyncPublishStatusAction;
use App\Contracts\OpsAlerter;
use App\Contracts\PublishCredentials;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use App\Support\Observability\TraceContext;
use App\Support\Publishing\VendorPostRef;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
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
 *
 * **This endpoint fails closed.** The signature is the only authorisation it has, so a delivery
 * that cannot be verified is refused — including when the organization has no webhook secret
 * stored yet. It used to trust those, which meant anyone who learned a `job_id` could mark a post
 * published (with a URL the client would see) or failed on any organization that had not yet
 * pasted its secret, i.e. every new one. `publishing.webhook_allow_unsigned` is a local-development
 * escape hatch and must stay false everywhere else.
 */
class UploadPostWebhookController extends Controller
{
    /**
     * Deliveries this old are rejected outright (replay protection), per the vendor's docs.
     */
    private const MAX_TIMESTAMP_SKEW_SECONDS = 300;

    public function handle(Request $request, OpsAlerter $alerter): JsonResponse
    {
        $event = $request->headers->get('X-Upload-Post-Event') ?? $request->string('event')->toString();

        if ($event !== 'upload_completed') {
            Log::info('Upload-Post webhook: ignoring non-publish event', ['event' => $event]);

            return response()->json(['received' => true]);
        }

        $jobId = (string) $request->input('job_id');
        $platform = strtolower((string) $request->input('platform'));

        if ($jobId === '' || $platform === '') {
            // Only the shape of the payload: the body is attacker-chosen on a public endpoint, so
            // it never goes into the log verbatim.
            Log::warning('Upload-Post webhook: payload missing job_id/platform', [
                'job_id' => $jobId,
                'platform' => $platform,
                'keys' => array_keys($request->all()),
            ]);

            return response()->json(['message' => 'Missing job_id or platform'], 422);
        }

        $verify = ! (bool) config('publishing.webhook_allow_unsigned', false);

        // Before the lookup, so a request with no usable signature header never costs a query.
        if ($verify && ! $this->hasWellFormedSignature($request)) {
            Log::warning('Upload-Post webhook: missing, malformed or stale signature header', [
                'job_id' => $jobId,
                'platform' => $platform,
            ]);

            return response()->json(['message' => 'Invalid signature'], 401);
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

        // Step I-16.1: join this webhook to the request/job that sent the target.
        Context::add(array_filter([
            TraceContext::POST_ID => $target->post_id,
            TraceContext::POST_TARGET_ID => $target->id,
            TraceContext::PUBLISH_CORRELATION_ID => $target->publish_correlation_id,
        ], fn ($value) => $value !== null));

        if ($verify && ! $this->hasValidSignature($request, $target, $platform, $alerter)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $result = (array) $request->input('result', []);
        $ok = (bool) ($result['success'] ?? false);

        app(SyncPublishStatusAction::class)(
            $target,
            $ok,
            $ok ? null : ((string) ($result['error'] ?? 'Upload-Post reported a failed publish.')),
            // Step M-3.1: the upload request id and the live post, for engagement metrics.
            ref: new VendorPostRef(
                $request->input('request_id'),
                $result['publish_id'] ?? $result['post_id'] ?? null,
                $result['url'] ?? null,
            ),
        );

        return response()->json(['received' => true]);
    }

    /**
     * Whether the request carries a signature header we could even check: a fresh, numeric
     * `X-Upload-Post-Timestamp` and an `X-Upload-Post-Signature: sha256=<hex>`. Cheap, needs no
     * database row, and rejects the stale-replay case — so it runs before the target lookup.
     */
    private function hasWellFormedSignature(Request $request): bool
    {
        $timestamp = $request->header('X-Upload-Post-Timestamp');

        if (! is_numeric($timestamp) || abs(time() - (int) $timestamp) > self::MAX_TIMESTAMP_SKEW_SECONDS) {
            return false;
        }

        return str_starts_with((string) $request->header('X-Upload-Post-Signature', ''), 'sha256=');
    }

    /**
     * Verifies `X-Upload-Post-Signature: sha256=<hex>` against
     * `HMAC_SHA256(webhook_secret, "{timestamp}.{raw body}")`, where `webhook_secret` is resolved
     * through `PublishCredentials` for the target's organization (Step B-5.1): the org's own
     * per-account secret in byo mode, or Alima's single platform secret in managed mode (business
     * workspaces), so a managed org's delivery is routed to the right org by its unique `job_id`
     * and verified with the platform secret — see https://docs.upload-post.com/api/webhooks#security.
     *
     * No secret configured means no way to verify, which means refuse. The organization is alerted
     * so somebody goes and pastes it, because until they do no publish result is recorded.
     *
     * `$platform` is the network the *payload* named (already normalised by the caller) — on a
     * refusal that is what the sender claimed, which is the useful thing to record.
     */
    private function hasValidSignature(Request $request, PostTarget $target, string $platform, OpsAlerter $alerter): bool
    {
        $organization = $target->socialAccount?->organization;
        $secret = $organization !== null
            ? app(PublishCredentials::class)->webhookSecretFor($organization)
            : '';

        if ($secret === '') {
            Log::error('Upload-Post webhook: no webhook secret configured for this organization, refusing the delivery', [
                'post_target_id' => $target->id,
                'org_id' => $organization?->id,
            ]);

            $alerter->alert(
                'webhooks.upload_post.secret_missing',
                'An Upload-Post webhook arrived for an organization with no webhook secret, so it was refused. Publish results will not be recorded until the secret is set.',
                ['post_id' => $target->post_id, 'post_target_id' => $target->id, 'org_id' => $organization?->id],
            );

            return false;
        }

        $timestamp = (string) $request->header('X-Upload-Post-Timestamp');
        $signature = substr((string) $request->header('X-Upload-Post-Signature', ''), strlen('sha256='));
        $expected = hash_hmac('sha256', "{$timestamp}.{$request->getContent()}", $secret);

        if (hash_equals($expected, $signature)) {
            return true;
        }

        Log::warning('Upload-Post webhook: invalid signature', ['post_target_id' => $target->id]);

        // Never the signature itself — only which target it claimed to be for.
        $alerter->alert('webhooks.upload_post.signature_failed', 'An Upload-Post webhook failed signature verification', [
            'post_id' => $target->post_id,
            'post_target_id' => $target->id,
            'platform' => $platform,
            'ip' => $request->ip(),
        ]);

        return false;
    }
}
