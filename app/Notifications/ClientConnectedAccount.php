<?php

namespace App\Notifications;

use App\Models\SocialAccount;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Step M-2.8 — a client connected one of their social accounts themselves.
 */
class ClientConnectedAccount extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly SocialAccount $account, private readonly ?string $by = null) {}

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
        return [
            'title' => 'Account connected',
            'body' => sprintf(
                '%s connected %s (%s) for %s.',
                $this->by ?? 'The client',
                $this->account->platform->label(),
                $this->account->handle,
                $this->account->client->name ?? 'a client',
            ),
            'url' => route('clients.show', $this->account->client_id),
            'social_account_id' => $this->account->id,
        ];
    }
}
