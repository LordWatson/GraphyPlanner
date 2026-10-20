<?php

namespace App\Mail;

use App\Models\GridPlan;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Step 18.8 — sent to a client's `approval_email` (via Resend) when a grid plan moves to
 * `waiting_client`, with a grid-scoped review link.
 */
class ClientGridReviewRequestedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public GridPlan $plan,
        public string $reviewUrl,
    ) {
        $this->onQueue(Queues::MAIL);
    }

    public function build(): self
    {
        return $this
            ->subject("Your new grid is ready to look at — {$this->plan->client->name}")
            ->view('emails.client-grid-review-requested');
    }
}
