<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppClient;
use App\Models\Organization;
use App\Support\Publishing\VendorHttp;
use App\Support\WhatsApp\PhoneNumber;
use App\Support\WhatsApp\WhatsAppSendResult;
use App\Support\WhatsApp\WhatsAppTemplateMessage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step W-0.2 — WhatsApp through Meta's Business Cloud API
 * (`POST /{phone_number_id}/messages`, developers.facebook.com/docs/whatsapp/cloud-api).
 *
 * Retries like a publish, not like a read: `VendorHttp::forPublish()` retries only a 429, because
 * Meta has no idempotency key for a message and a 5xx may already have delivered it — the same
 * reasoning as the publish POST, where a duplicate is worse than a failure somebody can retry.
 * (The plan's W-0.2 asked for a 5xx retry as well; this is the deliberate difference.)
 *
 * Nothing here throws: a failed send comes back as a `WhatsAppSendResult`, so a notification can
 * never break the thing that triggered it.
 *
 * Logging is deliberately thin. Meta's own error text can quote the recipient's full number back
 * at us (131030 lists it), so the boundary logs the error code, its subcode and our own reason —
 * never Meta's message, never the token, never the message body, and never more of the number
 * than its last four digits.
 */
class CloudApiWhatsAppClient implements WhatsAppClient
{
    public function __construct(private readonly WhatsAppCredentials $credentials) {}

    public function sendTemplate(Organization $organization, string $toPhone, WhatsAppTemplateMessage $message): WhatsAppSendResult
    {
        return $this->send($organization, $toPhone, [
            'type' => 'template',
            'template' => $message->toPayload(),
        ], $message->name);
    }

    public function sendSessionText(Organization $organization, string $toPhone, string $body): WhatsAppSendResult
    {
        return $this->send($organization, $toPhone, [
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ], 'session text');
    }

    public function verifyCredentials(Organization $organization): bool
    {
        if (! $this->credentials->isConfiguredFor($organization)) {
            return false;
        }

        try {
            // Reading the number's own record proves the token, the id, and that the two belong
            // together — and messages nobody.
            $response = VendorHttp::forReads($this->client($organization))
                ->get($this->credentials->phoneNumberIdFor($organization));
        } catch (Throwable $e) {
            Log::warning('WhatsApp credential check could not reach Meta', [
                'org_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $response->successful() && filled($response->json('id'));
    }

    /**
     * @param  array<string, mixed>  $message  the `type` and its matching object
     * @param  string  $what  template name (or "session text"), for the log line
     */
    private function send(Organization $organization, string $toPhone, array $message, string $what): WhatsAppSendResult
    {
        if (! $this->credentials->isConfiguredFor($organization)) {
            return WhatsAppSendResult::notConfigured();
        }

        $to = PhoneNumber::e164($toPhone);

        if ($to === null) {
            // Never reaches Meta: a number with no country code cannot be dialled, and guessing
            // one would message a stranger.
            return WhatsAppSendResult::failed('invalid_number', 'That number is not in a form WhatsApp can reach (it needs a country code).');
        }

        try {
            $response = VendorHttp::forPublish($this->client($organization))->post(
                $this->credentials->phoneNumberIdFor($organization).'/messages',
                ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, ...$message],
            );
        } catch (Throwable $e) {
            Log::warning('WhatsApp send could not reach Meta', [
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
        $messageId = $response->json('messages.0.id');

        if ($response->successful() && is_string($messageId) && $messageId !== '') {
            Log::info('WhatsApp message accepted', [
                'org_id' => $organization->id,
                'message' => $what,
                'to' => PhoneNumber::mask($to),
                'wa_message_id' => $messageId,
            ]);

            return WhatsAppSendResult::sent($messageId);
        }

        $code = is_numeric($response->json('error.code')) ? (int) $response->json('error.code') : null;
        $subcode = is_numeric($response->json('error.error_subcode')) ? (int) $response->json('error.error_subcode') : null;
        $reason = $this->reasonFor($code, $response->status());

        Log::warning('WhatsApp send refused', [
            'org_id' => $organization->id,
            'message' => $what,
            'to' => PhoneNumber::mask($to),
            'status' => $response->status(),
            'error_code' => $code,
            'error_subcode' => $subcode,
            'reason' => $reason,
        ]);

        return WhatsAppSendResult::failed($code === null ? 'http_'.$response->status() : (string) $code, $reason);
    }

    /**
     * Meta's error codes, as at developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes
     * (read 8 Nov 2026). Anything unlisted keeps its numeric code, so the log still identifies it.
     */
    private function reasonFor(?int $code, int $status): string
    {
        return match (true) {
            $code === 131026 => 'WhatsApp cannot deliver to that number — it has no WhatsApp account, or it cannot receive messages.',
            $code === 131047 => 'The 24-hour reply window has closed, so this send needs an approved template.',
            $code === 131030 => 'That number is not on the Meta app allowed-recipient list (the app is still in test mode).',
            $code === 190 => 'The WhatsApp access token is invalid or has expired.',
            $code === 100 => 'Meta rejected the request — usually a template variable count that no longer matches the approved copy.',
            $code !== null && $code >= 132000 && $code < 133000 => 'The message template was refused: check its name, locale and variable count against the approved version.',
            $code === 130429 || $code === 80007 || $code === 4 || $status === 429 => 'Meta is rate-limiting this number. Nothing was delivered.',
            $code !== null => "Meta refused the message (error {$code}).",
            default => "Meta refused the message (HTTP {$status}).",
        };
    }

    private function client(Organization $organization): PendingRequest
    {
        $version = trim((string) config('services.whatsapp.graph_version', 'v21.0'), '/');
        $baseUrl = rtrim((string) config('services.whatsapp.base_url', 'https://graph.facebook.com'), '/');

        return Http::baseUrl($baseUrl.'/'.$version)
            ->withToken($this->credentials->accessTokenFor($organization))
            ->acceptJson();
    }
}
