<?php

namespace App\Actions\Inbox;

use App\Enums\InboxStatus;
use App\Models\InboxConversation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step M-9.2 — move an inbox thread through its workflow: open ↔ snoozed ↔ resolved. Resolving
 * stamps who/when; reopening clears it; snoozing records until when (and hides the thread from the
 * active list until then). Every move is a single-row write wrapped for consistency and logged.
 */
class TransitionConversationAction
{
    public function __invoke(InboxConversation $conversation, InboxStatus $to, User $actor, ?CarbonInterface $snoozedUntil = null): InboxConversation
    {
        if ($to === InboxStatus::Snoozed && $snoozedUntil === null) {
            throw ValidationException::withMessages(['snoozed_until' => 'Choose when the conversation should resurface.']);
        }

        $conversation->status = $to;
        $conversation->snoozed_until = $to === InboxStatus::Snoozed ? $snoozedUntil : null;

        if ($to === InboxStatus::Resolved) {
            $conversation->resolved_at = now();
            $conversation->resolved_by = $actor->id;
            $conversation->is_reply_needed = false;
        } else {
            $conversation->resolved_at = null;
            $conversation->resolved_by = null;
        }

        $conversation->save();

        Log::info('Inbox conversation transitioned', [
            'org_id' => $conversation->org_id,
            'inbox_conversation_id' => $conversation->id,
            'status' => $to->value,
            'actor_id' => $actor->id,
        ]);

        return $conversation;
    }
}
