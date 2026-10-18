<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Notifications\Campaigns\CampaignNotification;

/**
 * Step C-6.3 — the campaign's first post is out.
 */
class CampaignWentLive extends CampaignNotification
{
    public function __construct(private readonly Campaign $campaign) {}

    protected function campaignId(): int
    {
        return $this->campaign->id;
    }

    protected function title(): string
    {
        return 'Campaign is live';
    }

    protected function body(): string
    {
        return "The first post for {$this->campaign->name} just went out";
    }

    protected function url(object $notifiable): string
    {
        return route('campaigns.show', $this->campaign);
    }
}
