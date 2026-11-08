<?php

namespace App\Actions\WhatsApp;

use App\Enums\WhatsAppMessageStatus;
use App\Models\ClientContact;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSuppression;
use App\Support\Notifications\NotificationPreferences;
use App\Support\WhatsApp\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Step W-3.1 / W-1.3 — what Twilio tells us after the fact: how a message got on, and anything the
 * recipient sent back.
 *
 * Two kinds of callback arrive at the same endpoint, told apart by their parameters:
 *
 * - a **status callback** carries `MessageSid` + `MessageStatus`, and updates the matching
 *   `whatsapp_messages` row;
 * - an **inbound message** carries `From` + `Body`, and is read only for consent control — STOP
 *   and START. Two-way conversation is the Inbox module's job, not this one.
 *
 * Nothing here trusts the payload beyond its shape: the controller has already verified the
 * signature, and these values still only ever select a row by id or a number by equality.
 */
class HandleWhatsAppWebhookAction
{
    /**
     * Keywords Meta and Twilio both treat as an opt-out. Matched on the whole message, so "stop
     * sending me the invoice ones" is a conversation for a human, not an unsubscribe.
     */
    private const STOP_WORDS = ['STOP', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'STOPALL'];

    private const START_WORDS = ['START', 'UNSTOP', 'YES'];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __invoke(array $payload): void
    {
        $from = $this->phone($payload['From'] ?? null);
        $body = trim((string) ($payload['Body'] ?? ''));

        if ($from !== null && $body !== '') {
            $this->handleInbound($from, $body);

            return;
        }

        $this->handleStatus($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleStatus(array $payload): void
    {
        $sid = (string) ($payload['MessageSid'] ?? $payload['SmsSid'] ?? '');
        // Twilio sends `MessageStatus`; older/SMS-shaped callbacks send `SmsStatus`.
        $status = (string) ($payload['MessageStatus'] ?? $payload['SmsStatus'] ?? '');
        $errorCode = $payload['ErrorCode'] ?? null;

        if ($sid === '' || $status === '') {
            Log::info('WhatsApp webhook: nothing to do with this callback', ['keys' => array_keys($payload)]);

            return;
        }

        $mapped = WhatsAppMessageStatus::fromProvider($status);
        $message = WhatsAppMessage::query()->where('provider_message_id', $sid)->first();

        if (! $message instanceof WhatsAppMessage) {
            // A message from before this table existed, or from another environment sharing the
            // Twilio account. Acknowledged, not an error.
            Log::info('WhatsApp webhook: no message row for this sid', ['status' => $status]);

            return;
        }

        if ($mapped === null) {
            Log::info('WhatsApp webhook: unmapped status', ['status' => $status, 'message_id' => $message->id]);

            return;
        }

        // Callbacks can arrive out of order (`delivered` after `read`), and a later status must not
        // walk the row backwards.
        if ($this->rank($mapped) < $this->rank($message->status)) {
            return;
        }

        $message->forceFill([
            'status' => $mapped,
            'delivered_at' => in_array($mapped, [WhatsAppMessageStatus::Delivered, WhatsAppMessageStatus::Read], true)
                ? ($message->delivered_at ?? now())
                : $message->delivered_at,
            'error_code' => is_numeric($errorCode) ? (string) (int) $errorCode : $message->error_code,
        ])->save();

        // 21610 is Twilio's "this recipient has opted out" — the one status callback that has to
        // change what we do next, not just what we show.
        if ((int) $errorCode === 21610) {
            WhatsAppSuppression::suppress($message->to_phone, WhatsAppSuppression::REASON_OPTED_OUT, $message->org_id);
            $this->withdrawConsent($message->to_phone);
        }
    }

    private function handleInbound(string $from, string $body): void
    {
        $word = Str::upper(preg_replace('/[^A-Za-z]/', '', $body) ?? '');

        if (in_array($word, self::STOP_WORDS, true)) {
            // Global, with no org id: they asked us to stop, and deciding that they only meant one
            // of the agencies on this number is not ours to assume.
            WhatsAppSuppression::suppress($from, WhatsAppSuppression::REASON_STOP);
            $this->withdrawConsent($from);

            Log::info('WhatsApp inbound: opt-out', ['from' => PhoneNumber::mask($from)]);

            return;
        }

        if (in_array($word, self::START_WORDS, true)) {
            // Releases the suppression only. It does **not** re-grant consent: that is a decision
            // they make in the portal or with their agency, not a word in a chat window.
            WhatsAppSuppression::release($from);

            Log::info('WhatsApp inbound: opt-out released', ['from' => PhoneNumber::mask($from)]);

            return;
        }

        // Anything else is a conversation. We do not reply, and we do not store it: inbound chat
        // is the Inbox module (M-9), and keeping a copy here would be a second place client
        // messages live.
        Log::info('WhatsApp inbound: ignored non-keyword message', ['from' => PhoneNumber::mask($from)]);
    }

    /**
     * Turn off every WhatsApp preference and consent held against this number, so nothing tries
     * again even once a suppression is released.
     */
    private function withdrawConsent(string $phone): void
    {
        $e164 = PhoneNumber::e164($phone);

        if ($e164 === null) {
            return;
        }

        DB::transaction(function () use ($e164) {
            User::query()->where('phone', $e164)->get()->each(function (User $user) {
                $preferences = $user->notification_preferences ?? [];

                foreach (array_keys($preferences) as $category) {
                    $preferences[$category]['whatsapp'] = false;
                }

                $user->forceFill([
                    'notification_preferences' => NotificationPreferences::sanitize([], $preferences),
                    // The number stays, unverified: they may well verify it again themselves.
                    'phone_verified_at' => null,
                ])->save();
            });

            ClientContact::query()
                ->withoutGlobalScopes()
                ->where('phone', $e164)
                ->get()
                ->each(function (ClientContact $contact) {
                    $contact->setWhatsAppConsent(false, 'inbound');
                    $contact->save();
                });
        });
    }

    /**
     * Twilio's statuses in the order they progress, so an out-of-order callback cannot undo a
     * later one. `Skipped` is not part of the sequence — nothing was sent — so it sits at the
     * bottom and any real status replaces it.
     */
    private function rank(WhatsAppMessageStatus $status): int
    {
        return match ($status) {
            WhatsAppMessageStatus::Skipped => 0,
            WhatsAppMessageStatus::Sent => 1,
            WhatsAppMessageStatus::Failed => 2,
            WhatsAppMessageStatus::Delivered => 3,
            WhatsAppMessageStatus::Read => 4,
        };
    }

    private function phone(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        // Twilio prefixes the channel: `whatsapp:+447700900123`.
        return PhoneNumber::e164(Str::after($value, 'whatsapp:'));
    }
}
