<?php

namespace App\Notifications;

use App\Models\SocialAccount;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Step I-3.9 / M-2.7 — a social account's connection expires in 7 days / 1 day and couldn't be
 * refreshed automatically. Sent once per stage by `CheckTokenExpiryAction`.
 */
class SocialAccountTokenExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly SocialAccount $account, private readonly string $stage) {}

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['database' => Queues::NOTIFICATIONS, 'mail' => Queues::MAIL];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $when = $this->stage === SocialAccount::TOKEN_EXPIRES_TOMORROW ? 'tomorrow' : 'within a week';

        return [
            'title' => 'Connection expiring',
            'body' => sprintf(
                '%s (%s) for %s stops working %s. Reconnect it to keep it running.',
                $this->account->platform->label(),
                $this->account->handle,
                $this->account->client->name ?? 'a client',
                $when,
            ),
            'url' => route('clients.show', $this->account->client_id),
            'social_account_id' => $this->account->id,
        ];
    }
}
