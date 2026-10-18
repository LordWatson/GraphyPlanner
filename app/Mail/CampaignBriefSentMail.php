<?php

namespace App\Mail;

use App\Models\Campaign;
use App\Models\CampaignBriefRevision;
use App\Models\User;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Step C-2.3 — tells a client portal contact that a campaign brief is ready for their sign-off,
 * linking to the campaign page in the portal (where they approve or ask for changes). Queued and
 * sent only after the revision is committed.
 */
class CampaignBriefSentMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Campaign $campaign,
        public CampaignBriefRevision $revision,
        public User $contact,
    ) {
        $this->onQueue(Queues::MAIL);
    }

    public function build(): self
    {
        return $this
            ->subject("Your brief for {$this->campaign->name} is ready to sign off")
            ->view('emails.campaign-brief-sent', [
                'url' => route('portal.campaigns.show', $this->campaign).'#brief',
            ]);
    }
}
