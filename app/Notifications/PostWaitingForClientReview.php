<?php

namespace App\Notifications;

use App\Models\Post;
use App\Models\User;
use App\Support\Queues;
use App\Support\WhatsApp\MessageTemplate;
use App\Support\WhatsApp\WhatsAppRouting;
use App\Support\WhatsApp\WhatsAppTemplateMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notifies client-portal users (`Role::ClientReviewer`) that a post is ready for their review —
 * dispatched from `TransitionPostStatusAction` whenever a post lands in `waiting_client`,
 * alongside the existing `ClientReviewRequestedMail` email.
 */
class PostWaitingForClientReview extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Step W-2.1 — `$reviewUrl` is the per-recipient link the email already mints: a magic portal
     * link for a contact who has a login, the token-only `/review/:token` otherwise. Null falls
     * back to the portal route, which is right for a signed-in reviewer.
     */
    public function __construct(
        private readonly Post $post,
        private readonly ?string $reviewUrl = null,
    ) {}

    /**
     * Step I-2.8: bell notifications and mail each run on their own queue.
     *
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['database' => Queues::NOTIFICATIONS, 'mail' => Queues::MAIL, 'whatsapp' => Queues::WHATSAPP];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // A client contact has no bell to ring — it is not a login — so WhatsApp is the only
        // channel it takes. A portal reviewer keeps the bell and adds WhatsApp if they asked for it.
        $channels = $notifiable instanceof User ? ['database'] : [];

        return WhatsAppRouting::with($channels, $notifiable, static::class);
    }

    /**
     * Step W-2.1 — the headline message. One tap to the review screen.
     */
    public function toWhatsApp(object $notifiable): WhatsAppTemplateMessage
    {
        return MessageTemplate::PostWaitingClient->fill([
            'client_name' => $notifiable->name ?? 'there',
            'post_summary' => $this->post->title ?? 'a new post',
            'review_url' => $this->reviewUrl ?? route('portal.posts.show', $this->post),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Post ready for review',
            'body' => 'A post is waiting for your review',
            'url' => route('portal.posts.show', $this->post),
            'post_id' => $this->post->id,
        ];
    }
}
