<?php

namespace App\Services\Notifications;

use App\Contracts\ChatNotifier;
use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts to an organization's own incoming-webhook URL (`organizations.chat_webhook_url`).
 *
 * Why a webhook URL rather than a Slack app: it needs no OAuth flow, no app review and no token
 * refresh, and the same shape works for Microsoft Teams — both accept a JSON POST to a per-channel
 * URL and render the `text` field. That is the whole integration, which is the point: the research
 * is clear that an agency's approvals leak into Slack whatever the tool does, so the cheapest thing
 * that puts the message where the work already happens is worth more than a richer one later.
 *
 * Delivery is best-effort by contract. Every failure is caught and logged: a chat message always
 * rides on the back of something that has already happened — a client approving, a publish failing
 * — and must never be what breaks it.
 */
class SlackChatNotifier implements ChatNotifier
{
    public function notifyOrganization(Organization $organization, string $title, string $body, ?string $url = null): void
    {
        $webhook = $this->webhookFor($organization);

        if ($webhook === null) {
            return;
        }

        try {
            $response = Http::timeout((int) config('notifications.chat_timeout', 5))
                ->asJson()
                ->post($webhook, ['text' => self::format($title, $body, $url)]);

            if ($response->failed()) {
                // The URL itself is the credential, so it never reaches the log.
                Log::warning('Chat notification rejected', [
                    'org_id' => $organization->id,
                    'status' => $response->status(),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Chat notification could not be delivered', [
                'org_id' => $organization->id,
                'exception' => $e::class,
            ]);
        }
    }

    public function isConfiguredFor(Organization $organization): bool
    {
        return $this->webhookFor($organization) !== null;
    }

    /**
     * One line for the channel: what happened, then the detail, then a link straight to it.
     * Slack's `mrkdwn` link syntax; Teams shows the raw URL, which is still clickable.
     */
    public static function format(string $title, string $body, ?string $url = null): string
    {
        $text = "*{$title}* — {$body}";

        return $url === null ? $text : $text." <{$url}|Open>";
    }

    /**
     * The organization's webhook URL, or null when it has none or it is not a URL we will post to.
     *
     * The scheme check is not paranoia about the owner: a stored value is posted to by a queue
     * worker, so `http://` (plaintext credential over the wire) and anything non-HTTP must not be
     * reachable from a settings field.
     */
    private function webhookFor(Organization $organization): ?string
    {
        $url = trim((string) ($organization->chat_webhook_url ?? ''));

        if ($url === '' || ! str_starts_with($url, 'https://')) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) === false ? null : $url;
    }
}
