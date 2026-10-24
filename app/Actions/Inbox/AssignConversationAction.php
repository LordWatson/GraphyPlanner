<?php

namespace App\Actions\Inbox;

use App\Models\InboxConversation;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step M-9.2 — assign (or unassign) an inbox thread to a staff member. A single-field write, so no
 * transaction is needed; logged with ids so an ownership change can be traced.
 */
class AssignConversationAction
{
    public function __invoke(InboxConversation $conversation, ?User $assignee): InboxConversation
    {
        $conversation->assigned_to = $assignee?->id;
        $conversation->save();

        Log::info('Inbox conversation assigned', [
            'org_id' => $conversation->org_id,
            'inbox_conversation_id' => $conversation->id,
            'assigned_to' => $assignee?->id,
        ]);

        return $conversation;
    }
}
