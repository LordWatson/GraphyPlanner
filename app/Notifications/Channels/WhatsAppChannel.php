<?php

namespace App\Notifications\Channels;

use App\Contracts\WhatsAppClient;
use App\Enums\WhatsAppMessageStatus;
use App\Models\ClientContact;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSuppression;
use App\Support\WhatsApp\MessageTemplate;
use App\Support\WhatsApp\PhoneNumber;
use App\Support\WhatsApp\WhatsAppSendResult;
use App\Support\WhatsApp\WhatsAppTemplateMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Step W-0.3 — the `whatsapp` notification channel, registered in `AppServiceProvider`. A
 * notification reaches it by returning `'whatsapp'` from `via()`, which
 * `NotificationPreferences::wantsWhatsApp()` (members) or `ClientContact::receives_whatsapp`
 * (clients) decides.
 *
 * Everything that must be true before a message goes out is checked here, in one place, so a new
 * notification cannot forget any of it:
 *
 * 1. a route (a verified number for a member, consent for a client contact),
 * 2. an organization to send as — credentials are per-organization,
 * 3. the number is not suppressed (Step W-1.3) — which outranks every preference,
 * 4. the organization is not flooding (Step W-3.2).
 *
 * Every failed check is a silent no-op, logged at `info` and recorded as a `Skipped`
 * `WhatsAppMessage` row with the reason — so "why didn't my client get it?" is answerable without
 * a log search. Nothing throws: a notification fans out to several channels, and the bell and the
 * email must not fail because a phone could not be reached.
 */
class WhatsAppChannel
{
    public function __construct(private readonly WhatsAppClient $client) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $organization = $this->organizationFor($notifiable);
        $to = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor('whatsapp', $notification)
            : null;

        if (! is_string($to) || $to === '') {
            // Normal, not an error: no verified number, or no consent.
            $this->skip('no WhatsApp route', $notifiable, $notification, $organization);

            return;
        }

        if (! $organization instanceof Organization) {
            // Credentials are resolved per organization, so a recipient with none — an on-demand
            // `Notification::route()` among them — cannot be sent to.
            $this->skip('no organization behind the recipient', $notifiable, $notification, null, $to);

            return;
        }

        if (WhatsAppSuppression::isSuppressed($to, $organization->id)) {
            // They told us to stop, or the number is not reachable. Outranks any opt-in.
            $this->skip('number is suppressed', $notifiable, $notification, $organization, $to);

            return;
        }

        if ($this->isFlooding($organization)) {
            $this->skip('organization send rate exceeded', $notifiable, $notification, $organization, $to);

            return;
        }

        $message = $this->messageFor($notifiable, $notification);
        $result = $this->client->sendTemplate($organization, $to, $message);

        $this->record($notifiable, $notification, $organization, $to, $message->template, $result);

        if ($result->shouldSuppress()) {
            // Step W-1.3: a number WhatsApp will never deliver to, or one whose owner has opted
            // out at Twilio's end. Stop now rather than on every future notification.
            WhatsAppSuppression::suppress($to, WhatsAppSuppression::REASON_UNDELIVERABLE, $organization->id);
        }
    }

    /**
     * The notification's own template message, or one derived from what it already puts in the
     * bell.
     *
     * The plan (W-0.3) called for a `SendsWhatsApp` trait for this. It is one fallback used in one
     * place, so it lives here instead: a notification that wants control declares `toWhatsApp()`
     * and nothing has to remember to `use` anything.
     */
    private function messageFor(object $notifiable, Notification $notification): WhatsAppTemplateMessage
    {
        if (method_exists($notification, 'toWhatsApp')) {
            $message = $notification->toWhatsApp($notifiable);

            if ($message instanceof WhatsAppTemplateMessage) {
                return $message;
            }
        }

        $data = method_exists($notification, 'toArray') ? (array) $notification->toArray($notifiable) : [];

        return MessageTemplate::Notification->fill([
            'title' => Str::limit((string) ($data['title'] ?? 'Alima'), 60, ''),
            'body' => Str::limit((string) ($data['body'] ?? ''), 240, ''),
            'url' => (string) ($data['url'] ?? ''),
        ]);
    }

    /**
     * Step W-3.2 — a crude ceiling per organization per minute, so a loop that fans a notification
     * out to a whole audience cannot turn into a few thousand WhatsApp messages. Twilio paces its
     * own sending and reports its limits with a retryable error, so this only has to catch the
     * runaway case; the default is high enough that normal traffic never sees it.
     */
    private function isFlooding(Organization $organization): bool
    {
        $perMinute = max(1, (int) config('services.whatsapp.per_minute', 60));

        if (RateLimiter::tooManyAttempts('whatsapp-send:'.$organization->id, $perMinute)) {
            return true;
        }

        RateLimiter::hit('whatsapp-send:'.$organization->id, 60);

        return false;
    }

    private function organizationFor(object $notifiable): ?Organization
    {
        // Every WhatsApp-routable recipient belongs to an organization — a member, a client-portal
        // reviewer, or a client contact.
        if (! $notifiable instanceof Model || ! method_exists($notifiable, 'organization')) {
            return null;
        }

        $organization = $notifiable->getRelationValue('organization');

        return $organization instanceof Organization ? $organization : null;
    }

    private function record(
        object $notifiable,
        Notification $notification,
        Organization $organization,
        string $to,
        MessageTemplate $template,
        WhatsAppSendResult $result,
    ): void {
        WhatsAppMessage::query()->create([
            'org_id' => $organization->id,
            'user_id' => $notifiable instanceof User ? $notifiable->id : null,
            'client_contact_id' => $notifiable instanceof ClientContact ? $notifiable->id : null,
            'to_phone' => PhoneNumber::e164($to) ?? $to,
            'notification_type' => $notification::class,
            'template' => $template->value,
            'provider_message_id' => $result->messageId,
            'status' => $result->ok ? WhatsAppMessageStatus::Sent : WhatsAppMessageStatus::Failed,
            'error_code' => $result->errorCode,
            'error_reason' => $result->errorReason,
        ]);
    }

    /**
     * A message that never left the building, with the reason — both in the log and in the message
     * log an agency can read. Written without an organization only when there is none to write it
     * against, in which case the log line is the whole record.
     */
    private function skip(
        string $why,
        object $notifiable,
        Notification $notification,
        ?Organization $organization,
        ?string $to = null,
    ): void {
        Log::info('WhatsApp notification skipped', [
            'reason' => $why,
            'notification' => $notification::class,
            'notifiable' => $notifiable::class,
            'notifiable_id' => $notifiable instanceof Model ? $notifiable->getKey() : null,
            'org_id' => $organization?->id,
            'to' => PhoneNumber::mask($to),
        ]);

        if ($organization === null || $to === null) {
            return;
        }

        WhatsAppMessage::query()->create([
            'org_id' => $organization->id,
            'user_id' => $notifiable instanceof User ? $notifiable->id : null,
            'client_contact_id' => $notifiable instanceof ClientContact ? $notifiable->id : null,
            'to_phone' => PhoneNumber::e164($to) ?? $to,
            'notification_type' => $notification::class,
            'template' => $this->templateNameFor($notifiable, $notification),
            'status' => WhatsAppMessageStatus::Skipped,
            'error_reason' => $why,
        ]);
    }

    /**
     * Which template *would* have been used. Building the message can throw if a notification
     * fills it wrongly, and a skip row must never be the thing that surfaces that.
     */
    private function templateNameFor(object $notifiable, Notification $notification): string
    {
        try {
            return $this->messageFor($notifiable, $notification)->template->value;
        } catch (\Throwable) {
            return MessageTemplate::Notification->value;
        }
    }
}
