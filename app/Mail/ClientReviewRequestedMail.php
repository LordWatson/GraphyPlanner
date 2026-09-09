<?php

namespace App\Mail;

use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a client's `approval_email` (via Resend) when a post moves into `waiting_client`,
 * containing the Step 0.13 review-portal link (a fresh, single-use-scoped `ReviewToken`).
 */
class ClientReviewRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Post $post,
        public string $reviewUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject("New content ready for your review — {$this->post->client->name}")
            ->view('emails.client-review-requested');
    }
}
