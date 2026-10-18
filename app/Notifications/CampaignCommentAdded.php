<?php

namespace App\Notifications;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\CampaignComment;
use App\Models\User;
use App\Notifications\Campaigns\CampaignNotification;
use App\Support\CommentMentionParser;
use Illuminate\Support\Str;

/**
 * Step C-5.3 — a new message in a campaign's conversation (or a mention in one). Links client
 * contacts to the portal page and staff to the workspace.
 */
class CampaignCommentAdded extends CampaignNotification
{
    public function __construct(
        private readonly Campaign $campaign,
        private readonly CampaignComment $comment,
        private readonly User $author,
        private readonly bool $mentioned = false,
    ) {}

    protected function campaignId(): int
    {
        return $this->campaign->id;
    }

    protected function title(): string
    {
        return $this->mentioned ? 'You were mentioned' : 'New campaign message';
    }

    protected function body(): string
    {
        $excerpt = Str::limit(trim(CommentMentionParser::toPlainText($this->comment->body)), 120);

        return $this->mentioned
            ? "{$this->author->name} mentioned you on {$this->campaign->name}: “{$excerpt}”"
            : "{$this->author->name} wrote on {$this->campaign->name}: “{$excerpt}”";
    }

    protected function url(object $notifiable): string
    {
        return $notifiable instanceof User && $notifiable->role === Role::ClientReviewer
            ? route('portal.campaigns.show', $this->campaign).'#conversation'
            : route('campaigns.show', $this->campaign).'#conversation';
    }
}
