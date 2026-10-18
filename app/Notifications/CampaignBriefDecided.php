<?php

namespace App\Notifications;

use App\Enums\ApprovalDecision;
use App\Models\Campaign;
use App\Models\CampaignBriefRevision;
use App\Models\User;
use App\Notifications\Campaigns\CampaignNotification;

/**
 * Step C-2.3 — tells the studio's Owners/Strategists the client approved a campaign brief or asked
 * for changes.
 */
class CampaignBriefDecided extends CampaignNotification
{
    public function __construct(
        private readonly Campaign $campaign,
        private readonly CampaignBriefRevision $revision,
        private readonly User $decidedBy,
    ) {}

    protected function campaignId(): int
    {
        return $this->campaign->id;
    }

    protected function title(): string
    {
        return $this->revision->decision === ApprovalDecision::Approved ? 'Brief approved' : 'Changes requested on a brief';
    }

    protected function body(): string
    {
        return $this->revision->decision === ApprovalDecision::Approved
            ? "{$this->decidedBy->name} approved the brief for {$this->campaign->name}"
            : "{$this->decidedBy->name} asked for changes to the brief for {$this->campaign->name}";
    }

    protected function url(object $notifiable): string
    {
        return route('campaigns.brief', $this->campaign);
    }
}
