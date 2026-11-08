<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Notifications\Campaigns\CampaignNotification;
use App\Support\WhatsApp\MessageTemplate;
use App\Support\WhatsApp\WhatsAppTemplateMessage;

/**
 * Step C-2.3 — a portal contact's bell: a campaign brief is waiting for their sign-off.
 */
class CampaignBriefReady extends CampaignNotification
{
    public function __construct(private readonly Campaign $campaign) {}

    protected function campaignId(): int
    {
        return $this->campaign->id;
    }

    protected function title(): string
    {
        return 'Brief ready to sign off';
    }

    protected function body(): string
    {
        return "The brief for {$this->campaign->name} is ready for your approval";
    }

    protected function url(object $notifiable): string
    {
        return route('portal.campaigns.show', $this->campaign).'#brief';
    }

    /**
     * Step W-2.5 — its own approved copy, because "your brief is ready" is a message a client
     * reads differently from a generic update.
     */
    public function toWhatsApp(object $notifiable): WhatsAppTemplateMessage
    {
        return MessageTemplate::CampaignBriefReady->fill([
            'campaign_name' => $this->campaign->name,
            'url' => $this->url($notifiable),
        ]);
    }
}
