<?php

namespace App\Notifications;

use App\Enums\ApprovalDecision;
use App\Models\Post;
use App\Support\Queues;
use App\Support\WhatsApp\MessageTemplate;
use App\Support\WhatsApp\WhatsAppRouting;
use App\Support\WhatsApp\WhatsAppTemplateMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notifies org users that a client approved or requested changes on a post — dispatched from
 * `TransitionPostStatusAction` whenever the destination status is `approved`/`changes_requested`
 * and the acting user is a `Role::ClientReviewer` (or the unauthenticated review portal, which
 * passes a null user).
 */
class PostApprovalDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Post $post,
        private readonly ApprovalDecision $decision,
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
        return WhatsAppRouting::with(['database'], $notifiable, static::class);
    }

    /**
     * Step W-2.3 — the studio hears the answer without opening the app.
     */
    public function toWhatsApp(object $notifiable): WhatsAppTemplateMessage
    {
        $data = $this->toArray($notifiable);

        return MessageTemplate::PostDecided->fill([
            // No nullsafe chain: `posts.client_id` is not nullable, so a post always has one.
            'client_name' => $this->post->client->name,
            'decision' => $this->decision === ApprovalDecision::Approved ? 'approved' : 'asked for changes on',
            'post_summary' => $this->post->summary(),
            'url' => (string) $data['url'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $client = $this->post->client?->name ?? 'A client';

        return [
            'title' => $this->decision === ApprovalDecision::Approved
                ? 'Post approved'
                : 'Changes requested',
            'body' => $this->decision === ApprovalDecision::Approved
                ? "{$client} approved a post"
                : "{$client} requested changes on a post",
            'url' => route('posts.edit', $this->post),
            'post_id' => $this->post->id,
        ];
    }
}
