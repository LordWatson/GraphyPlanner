<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Models\User;
use App\Notifications\Campaigns\CampaignNotification;

/**
 * Step C-5.2 — one summary per batch review: "Rae approved 6 posts and asked for changes on 1."
 */
class CampaignReviewSubmitted extends CampaignNotification
{
    public function __construct(
        private readonly Campaign $campaign,
        private readonly User $reviewer,
        private readonly int $approved,
        private readonly int $changes,
    ) {}

    protected function campaignId(): int
    {
        return $this->campaign->id;
    }

    protected function title(): string
    {
        return 'Client review in';
    }

    protected function body(): string
    {
        $parts = [];

        if ($this->approved > 0) {
            $parts[] = 'approved '.$this->approved.' '.($this->approved === 1 ? 'post' : 'posts');
        }

        if ($this->changes > 0) {
            $parts[] = 'asked for changes on '.$this->changes;
        }

        return "{$this->reviewer->name} ".implode(' and ', $parts)." in {$this->campaign->name}";
    }

    protected function url(object $notifiable): string
    {
        return route('campaigns.approvals', $this->campaign);
    }
}
