<?php

namespace App\Services\Inbox;

use App\Enums\InboxStatus;
use App\Models\InboxConversation;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

/**
 * Step M-9.2 — when has a comment been waiting on a reply too long? An open conversation that needs
 * a reply and has had no studio response is "overdue" once its last inbound comment is older than
 * the org's `inbox_sla_hours` (falling back to `config('inbox.sla.default_hours')` so the inbox has
 * a sensible SLA out of the box). A zero/negative effective SLA turns it off.
 */
class InboxSlaService
{
    public function slaHours(Organization $organization): int
    {
        return (int) ($organization->inbox_sla_hours ?? config('inbox.sla.default_hours', 24));
    }

    public function isOverdue(InboxConversation $conversation): bool
    {
        if ($conversation->status !== InboxStatus::Open || ! $conversation->is_reply_needed || $conversation->first_response_at !== null) {
            return false;
        }

        $hours = $this->slaHours($conversation->organization);

        return $hours > 0
            && $conversation->last_inbound_at !== null
            && $conversation->last_inbound_at->copy()->addHours($hours)->isPast();
    }

    /**
     * An org's overdue conversations in one query (for the reminder job and the Home count).
     *
     * @return Builder<InboxConversation>
     */
    public function overdueQuery(Organization $organization): Builder
    {
        $hours = $this->slaHours($organization);

        if ($hours <= 0) {
            return InboxConversation::query()->whereRaw('0 = 1');
        }

        return InboxConversation::query()
            ->where('org_id', $organization->id)
            ->where('status', InboxStatus::Open)
            ->where('is_reply_needed', true)
            ->whereNull('first_response_at')
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '<=', now()->subHours($hours));
    }
}
