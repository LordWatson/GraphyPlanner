<?php

namespace App\Notifications;

use App\Models\CampaignDeliverable;
use App\Notifications\Campaigns\CampaignNotification;

/**
 * Step C-4.4 — tells the assignee (only) that a campaign deliverable is theirs. Also used for the
 * "due tomorrow" and "overdue" reminders (`$kind`).
 */
class CampaignDeliverableAssigned extends CampaignNotification
{
    public const ASSIGNED = 'assigned';

    public const DUE_TOMORROW = 'due_tomorrow';

    public const OVERDUE = 'overdue';

    public function __construct(
        private readonly CampaignDeliverable $deliverable,
        private readonly string $kind = self::ASSIGNED,
    ) {}

    protected function campaignId(): int
    {
        return $this->deliverable->campaign_id;
    }

    protected function title(): string
    {
        return match ($this->kind) {
            self::DUE_TOMORROW => 'Due tomorrow',
            self::OVERDUE => 'Deliverable overdue',
            default => 'New deliverable for you',
        };
    }

    protected function body(): string
    {
        $campaign = $this->deliverable->campaign->name;

        return match ($this->kind) {
            self::DUE_TOMORROW => "“{$this->deliverable->title}” for {$campaign} is due tomorrow",
            self::OVERDUE => "“{$this->deliverable->title}” for {$campaign} is past its due date",
            default => "You’re on “{$this->deliverable->title}” for {$campaign}",
        };
    }

    protected function url(object $notifiable): string
    {
        return route('campaigns.plan', $this->deliverable->campaign_id);
    }
}
