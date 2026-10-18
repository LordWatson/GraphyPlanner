<?php

namespace App\Mail;

use App\Models\Campaign;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Step C-4.6 / C-5.2 — one email for a batch of a campaign's posts sent to the client, linking to
 * the portal's "Review this campaign" flow instead of N separate review links.
 */
class CampaignPostsReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Campaign $campaign,
        public int $count,
    ) {
        $this->onQueue(Queues::MAIL);
    }

    public function build(): self
    {
        return $this
            ->subject($this->count === 1
                ? "A post for {$this->campaign->name} is ready for your review"
                : "{$this->count} posts for {$this->campaign->name} are ready for your review")
            ->view('emails.campaign-posts-ready', [
                'url' => route('portal.campaigns.review', $this->campaign),
            ]);
    }
}
