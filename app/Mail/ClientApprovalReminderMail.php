<?php

namespace App\Mail;

use App\Models\Post;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Step M-6.5 (Phase 9) — a gentle reminder to the client that a post is still waiting on their
 * approval past the agency's SLA. Mirrors `ClientReviewRequestedMail`: carries a fresh review link,
 * sent at most once per 24h per post by `SendOverdueApprovalRemindersJob`.
 */
class ClientApprovalReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Post $post,
        public string $reviewUrl,
    ) {
        $this->onQueue(Queues::MAIL);
    }

    public function build(): self
    {
        return $this
            ->subject("A quick reminder — content waiting for your review ({$this->post->client->name})")
            ->view('emails.client-approval-reminder');
    }
}
