<?php

namespace App\Http\Controllers;

use App\Models\SuppressedEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives the mail provider's delivery events and suppresses the addresses that must not be sent
 * to again: hard bounces and spam complaints.
 *
 * Resend signs with the Svix scheme: `svix-signature` carries one or more space-separated
 * `v1,<base64 hmac>` values over `"{svix-id}.{svix-timestamp}.{raw body}"`, keyed on the endpoint
 * secret (`whsec_` + base64 key). More than one signature is normal during a secret rotation, so
 * any valid one is accepted.
 *
 * **Fails closed**, for the same reason `UploadPostWebhookController` does: the signature is the
 * only authorisation on a public endpoint, and the consequence of trusting a forged delivery here
 * is somebody else's address added to a platform-wide do-not-send list. No secret configured means
 * every delivery is refused.
 */
class ResendWebhookController extends Controller
{
    /**
     * Deliveries older than this are refused (replay protection), matching Svix's own window.
     */
    private const MAX_TIMESTAMP_SKEW_SECONDS = 300;

    /**
     * Provider event types that mean "never send here again". A soft bounce (`email.delivery_delayed`)
     * is not one of them — a full mailbox or a greylisting retry is temporary, and suppressing on
     * it would quietly lose a client who was simply away.
     */
    private const SUPPRESSING = [
        'email.bounced' => SuppressedEmail::REASON_BOUNCED,
        'email.complained' => SuppressedEmail::REASON_COMPLAINED,
    ];

    public function handle(Request $request): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('Resend webhook: refused an unverified delivery', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $type = (string) $request->input('type');
        $reason = self::SUPPRESSING[$type] ?? null;

        if ($reason === null) {
            // Delivered, opened, clicked, delayed — acknowledged so the provider stops retrying.
            return response()->json(['received' => true]);
        }

        $recipients = $request->input('data.to', []);

        foreach ((array) (is_array($recipients) ? $recipients : [$recipients]) as $email) {
            if (is_string($email)) {
                SuppressedEmail::record($email, $reason, 'resend');
            }
        }

        return response()->json(['received' => true]);
    }

    /**
     * Verifies the Svix signature header. Returns false when no secret is configured, so the
     * endpoint cannot be used until it is set up.
     */
    private function hasValidSignature(Request $request): bool
    {
        $secret = (string) config('services.resend.webhook_secret', '');

        if ($secret === '') {
            Log::error('Resend webhook: no webhook secret configured, refusing the delivery');

            return false;
        }

        $id = (string) $request->header('svix-id', '');
        $timestamp = $request->header('svix-timestamp');
        $header = (string) $request->header('svix-signature', '');

        if ($id === '' || $header === '' || ! is_numeric($timestamp)
            || abs(time() - (int) $timestamp) > self::MAX_TIMESTAMP_SKEW_SECONDS) {
            return false;
        }

        // `whsec_<base64>`: the key is the decoded part, not the prefixed string.
        $key = base64_decode(substr($secret, strlen('whsec_')), true);

        if ($key === false || $key === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$request->getContent()}", $key, true));

        foreach (explode(' ', $header) as $candidate) {
            // Only v1 signatures; a future scheme must be added deliberately, not accepted blindly.
            if (str_starts_with($candidate, 'v1,') && hash_equals($expected, substr($candidate, 3))) {
                return true;
            }
        }

        return false;
    }
}
