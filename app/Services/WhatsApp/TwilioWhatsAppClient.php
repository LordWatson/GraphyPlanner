<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppClient;
use App\Models\Organization;
use App\Support\Publishing\VendorHttp;
use App\Support\WhatsApp\PhoneNumber;
use App\Support\WhatsApp\WhatsAppFailure;
use App\Support\WhatsApp\WhatsAppSendResult;
use App\Support\WhatsApp\WhatsAppTemplateMessage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step W-0.2 — WhatsApp through Twilio (`POST /2010-04-01/Accounts/{sid}/Messages.json`,
 * twilio.com/docs/whatsapp, read 8 Nov 2026).
 *
 * Twilio rather than Meta's Cloud API direct: a BSP owns the Business Account, the number
 * registration and the template submissions, which is most of the work in getting WhatsApp live.
 * The REST API is a form POST with basic auth, so this uses the app's own `Http`/`VendorHttp`
 * stack like every other vendor here instead of pulling in `twilio/sdk` for two endpoints.
 *
 * - A **template** send is `ContentSid` + `ContentVariables` (Twilio's Content API). Business-
 *   initiated messages have to be an approved template, so `MessageTemplate` is a closed list and
 *   a template with no `ContentSid` configured is refused before anything is called.
 * - A **session text** send is `Body`, valid only inside the 24-hour window the recipient opened.
 * - `MessagingServiceSid` is used when configured and `From` otherwise. Twilio requires a
 *   messaging service for some Content API sends, and it is also what carries status callbacks for
 *   a sender pool.
 *
 * Retries like a publish, not a read: `VendorHttp::forPublish()` retries only a 429, because a
 * retried 5xx may deliver a second copy of a message somebody already has.
 *
 * Nothing here throws. Logging is deliberately thin: Twilio's own error text can quote the
 * recipient's number back at us, so the boundary logs the error code and our own reason — never
 * the auth token, the message body, or more of the number than its last four digits.
 */
class TwilioWhatsAppClient implements WhatsAppClient
{
    public function __construct(private readonly WhatsAppCredentials $credentials) {}

    public function sendTemplate(Organization $organization, string $toPhone, WhatsAppTemplateMessage $message): WhatsAppSendResult
    {
        $contentSid = $message->template->contentSid();

        if ($contentSid === null) {
            // Not a delivery failure: nobody has had this template approved yet. Refused here so
            // it shows up as a configuration problem rather than a Twilio 400.
            return WhatsAppSendResult::failed(
                'template_not_approved',
                sprintf('The "%s" WhatsApp template has no approved ContentSid yet.', $message->template->value),
                WhatsAppFailure::NotConfigured,
            );
        }

        return $this->send($organization, $toPhone, [
            'ContentSid' => $contentSid,
            'ContentVariables' => $message->contentVariables(),
        ], $message->template->value);
    }

    public function sendSessionText(Organization $organization, string $toPhone, string $body): WhatsAppSendResult
    {
        return $this->send($organization, $toPhone, ['Body' => $body], 'session text');
    }

    public function verifyCredentials(Organization $organization): bool
    {
        if (! $this->credentials->isConfiguredFor($organization)) {
            return false;
        }

        $accountSid = $this->credentials->accountSidFor($organization);

        try {
            // Reading the account proves the SID and the token together, and messages nobody.
            $response = VendorHttp::forReads($this->client($organization))
                ->get('Accounts/'.$accountSid.'.json');
        } catch (Throwable $e) {
            Log::warning('WhatsApp credential check could not reach Twilio', [
                'org_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $response->successful() && $response->json('sid') === $accountSid;
    }

    /**
     * @param  array<string, string>  $message  the content fields for this kind of send
     * @param  string  $what  template name (or "session text"), for the log line
     */
    private function send(Organization $organization, string $toPhone, array $message, string $what): WhatsAppSendResult
    {
        if (! $this->credentials->isConfiguredFor($organization)) {
            return WhatsAppSendResult::notConfigured();
        }

        $to = PhoneNumber::e164($toPhone);

        if ($to === null) {
            // Never reaches Twilio: a number with no country code cannot be dialled, and guessing
            // one would message a stranger.
            return WhatsAppSendResult::failed(
                'invalid_number',
                'That number is not in a form WhatsApp can reach (it needs a country code).',
                WhatsAppFailure::Permanent,
            );
        }

        $sender = $this->sender($organization);

        if ($sender === []) {
            return WhatsAppSendResult::notConfigured();
        }

        $payload = ['To' => 'whatsapp:'.$to, ...$message, ...$sender];
        $statusCallback = $this->statusCallback();

        if ($statusCallback !== null) {
            $payload['StatusCallback'] = $statusCallback;
        }

        try {
            $response = VendorHttp::forPublish($this->client($organization))
                ->asForm()
                ->post('Accounts/'.$this->credentials->accountSidFor($organization).'/Messages.json', $payload);
        } catch (Throwable $e) {
            Log::warning('WhatsApp send could not reach Twilio', [
                'org_id' => $organization->id,
                'message' => $what,
                'to' => PhoneNumber::mask($to),
                'error' => $e->getMessage(),
            ]);

            return WhatsAppSendResult::failed('unreachable', 'Could not reach WhatsApp. Nothing was delivered.');
        }

        return $this->result($response, $organization, $what, $to);
    }

    private function result(Response $response, Organization $organization, string $what, string $to): WhatsAppSendResult
    {
        $messageId = $response->json('sid');
        $status = $response->json('status');
        $code = $response->json('error_code') ?? $response->json('code');

        if ($response->successful() && is_string($messageId) && $messageId !== '' && $code === null) {
            Log::info('WhatsApp message accepted', [
                'org_id' => $organization->id,
                'message' => $what,
                'to' => PhoneNumber::mask($to),
                'wa_message_id' => $messageId,
                'status' => is_string($status) ? $status : null,
            ]);

            return WhatsAppSendResult::sent($messageId, is_string($status) ? $status : null);
        }

        $code = is_numeric($code) ? (int) $code : null;
        $failure = $this->failureFor($code, $response->status());
        $reason = $this->reasonFor($code, $response->status());

        Log::warning('WhatsApp send refused', [
            'org_id' => $organization->id,
            'message' => $what,
            'to' => PhoneNumber::mask($to),
            'status' => $response->status(),
            'error_code' => $code,
            'failure' => $failure->value,
            'reason' => $reason,
        ]);

        return WhatsAppSendResult::failed($code === null ? 'http_'.$response->status() : (string) $code, $reason, $failure);
    }

    /**
     * Twilio's error codes, as at twilio.com/docs/api/errors (read 8 Nov 2026).
     */
    private function failureFor(?int $code, int $status): WhatsAppFailure
    {
        return match (true) {
            // Opted out, not a WhatsApp number, or a number nobody can dial.
            $code === 21610 || $code === 63024 => WhatsAppFailure::Permanent,
            $code === 21211 || $code === 21614 || $code === 63003 => WhatsAppFailure::Permanent,
            // Content or policy: resending the same thing fails the same way.
            $code === 63005 || $code === 63013 || $code === 63021 => WhatsAppFailure::Permanent,
            // Freeform message outside the 24-hour window.
            $code === 63016 => WhatsAppFailure::Window,
            // Credentials or sender setup — ours to fix, and not by retrying.
            $code === 20003 || $code === 63007 => WhatsAppFailure::NotConfigured,
            default => WhatsAppFailure::Transient,
        };
    }

    private function reasonFor(?int $code, int $status): string
    {
        return match (true) {
            $code === 21610 => 'This number has opted out of messages from us. Only they can opt back in.',
            $code === 63024 || $code === 63003 => 'WhatsApp cannot deliver to that number — it has no WhatsApp account.',
            $code === 21211 || $code === 21614 => 'That is not a number WhatsApp can reach.',
            $code === 63016 => 'The 24-hour reply window has closed, so this send needs an approved template.',
            $code === 63005 || $code === 63013 || $code === 63021 => 'WhatsApp refused the message content. The template may need re-approval.',
            $code === 20003 => 'Twilio rejected the credentials for this organization.',
            $code === 63007 => 'No WhatsApp sender is set up for this organization in Twilio.',
            $code === 20429 || $code === 63018 || $status === 429 => 'Twilio is rate-limiting this sender. Nothing was delivered.',
            $code !== null => "Twilio refused the message (error {$code}).",
            default => "Twilio refused the message (HTTP {$status}).",
        };
    }

    /**
     * A messaging service wins: Twilio needs one for some Content API sends, and it is what routes
     * status callbacks for a pool of senders. `From` stays for a single-number account. An empty
     * array means neither is set up, which is a configuration problem, not a send.
     *
     * @return array<string, string>
     */
    private function sender(Organization $organization): array
    {
        $service = $this->credentials->messagingServiceSidFor($organization);

        if ($service !== '') {
            return ['MessagingServiceSid' => $service];
        }

        $from = $this->credentials->fromNumberFor($organization);

        return $from === '' ? [] : ['From' => 'whatsapp:'.$from];
    }

    /**
     * Where Twilio reports delivery (Step W-3.1). Null in local development, where Twilio cannot
     * reach us and a bad callback URL would fail the send itself.
     */
    private function statusCallback(): ?string
    {
        $url = (string) config('services.whatsapp.status_callback_url', '');

        if ($url === '') {
            return null;
        }

        return str_starts_with($url, 'https://') ? $url : null;
    }

    private function client(Organization $organization): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.whatsapp.base_url', 'https://api.twilio.com'), '/').'/2010-04-01')
            ->withBasicAuth(
                $this->credentials->accountSidFor($organization),
                $this->credentials->authTokenFor($organization),
            )
            ->acceptJson();
    }
}
