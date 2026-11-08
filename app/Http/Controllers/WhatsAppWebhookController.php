<?php

namespace App\Http\Controllers;

use App\Actions\WhatsApp\HandleWhatsAppWebhookAction;
use App\Models\Organization;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppCredentials;
use App\Support\WhatsApp\PhoneNumber;
use App\Support\WhatsApp\TwilioSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Step W-3.1 — Twilio's delivery statuses and inbound messages.
 *
 * **This endpoint fails closed.** The `X-Twilio-Signature` is the only authorisation it has, so a
 * request that cannot be verified is refused — including when there is no auth token to verify it
 * against. Without that, anyone who learned the URL could mark a message delivered or, worse, post
 * a "STOP" and silence a client's number.
 *
 * Which token verifies it depends on who the message belongs to, so the organization is resolved
 * first — from the message id for a status callback, or from the `To` sender number for an inbound
 * message — and its own token is tried before the platform's.
 *
 * The signed string includes every POST parameter exactly as Twilio sent it, which is why
 * `routes/web.php` keeps this path out of `TrimStrings` and `ConvertEmptyStringsToNull`.
 */
class WhatsAppWebhookController extends Controller
{
    public function handle(Request $request, WhatsAppCredentials $credentials, HandleWhatsAppWebhookAction $handle): JsonResponse
    {
        $params = $request->post();
        $organization = $this->organizationFor($params);
        $signature = (string) $request->headers->get('X-Twilio-Signature', '');
        $url = (string) (config('services.whatsapp.webhook_url') ?: $request->fullUrl());

        $tokens = array_filter([
            $organization instanceof Organization ? $credentials->authTokenFor($organization) : null,
            (string) config('services.whatsapp.auth_token', ''),
        ]);

        foreach ($tokens as $token) {
            if (TwilioSignature::isValid($url, $params, $signature, (string) $token)) {
                $handle($params);

                return response()->json(['received' => true]);
            }
        }

        // Only the shape of the payload: the body is attacker-chosen on a public endpoint, so it
        // never goes into the log verbatim.
        Log::warning('WhatsApp webhook: signature could not be verified', [
            'org_id' => $organization?->id,
            'keys' => array_keys($params),
            'had_signature' => $signature !== '',
            'candidate_tokens' => count($tokens),
        ]);

        return response()->json(['message' => 'Invalid signature'], 401);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function organizationFor(array $params): ?Organization
    {
        $sid = (string) ($params['MessageSid'] ?? $params['SmsSid'] ?? '');

        if ($sid !== '') {
            $message = WhatsAppMessage::query()
                ->withoutGlobalScopes()
                ->where('provider_message_id', $sid)
                ->first();

            if ($message instanceof WhatsAppMessage) {
                return $message->organization;
            }
        }

        // An inbound message: `To` is our own sender, which is how an organization with its own
        // number is identified.
        $to = PhoneNumber::e164(Str::after((string) ($params['To'] ?? ''), 'whatsapp:'));

        return $to === null
            ? null
            : Organization::query()->withoutGlobalScopes()->where('whatsapp_from', $to)->first();
    }
}
