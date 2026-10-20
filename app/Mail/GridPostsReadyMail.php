<?php

namespace App\Mail;

use App\Models\GridPlan;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Step 18.9 — one email for a grid's captions sent to the client as a batch, linking to the
 * portal's posts waiting on them (instead of one review link per post).
 */
class GridPostsReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public GridPlan $plan,
        public int $count,
    ) {
        $this->onQueue(Queues::MAIL);
    }

    public function build(): self
    {
        return $this
            ->subject("The captions for {$this->plan->name} are ready for your review")
            ->view('emails.grid-posts-ready', [
                'url' => route('portal.posts.index', ['status' => 'waiting_client']),
            ]);
    }
}
