<?php

namespace App\Actions\Inbox;

use App\Contracts\CommentSource;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageDirection;
use App\Exceptions\Inbox\CommentSourceException;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SavedReply;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Step M-9.3 — send one human-written (or human-approved) reply to an inbox conversation through the
 * vendor. This is the ONLY code path that calls `CommentSource::reply()`, and it only runs from an
 * explicit user request (the inbox "Send" button) — the AI draft action never sends (Phase M-9 exit
 * criterion: a human always sends).
 *
 * The outbound message is recorded `pending` first (committed), the vendor is called outside the
 * transaction so a failure leaves a visible `failed` row to retry, and only a confirmed send stamps
 * the delivery details and the conversation's first-response / reply-needed state.
 */
class SendInboxReplyAction
{
    public function __construct(private readonly CommentSource $source) {}

    public function __invoke(InboxConversation $conversation, User $sender, string $body, ?SavedReply $savedReply = null): InboxMessage
    {
        $text = trim($body);
        $account = $conversation->socialAccount;

        if ($account === null) {
            throw new CommentSourceException('This conversation has no connected account to reply from.');
        }

        $parentCommentId = $this->parentCommentId($conversation);

        $message = $conversation->messages()->create([
            'org_id' => $conversation->org_id,
            'direction' => MessageDirection::Outbound,
            'body' => $text,
            'sent_by' => $sender->id,
            'delivery_status' => MessageDeliveryStatus::Pending,
            'posted_at' => now(),
        ]);

        Log::info('Inbox reply sending', [
            'org_id' => $conversation->org_id,
            'inbox_conversation_id' => $conversation->id,
            'inbox_message_id' => $message->id,
            'sender_id' => $sender->id,
        ]);

        try {
            $result = $this->source->reply($account, $parentCommentId, $text);
        } catch (CommentSourceException $e) {
            $message->forceFill([
                'delivery_status' => MessageDeliveryStatus::Failed,
                'delivery_error' => Str::limit($e->getMessage(), 490),
            ])->save();

            Log::warning('Inbox reply failed', [
                'org_id' => $conversation->org_id,
                'inbox_conversation_id' => $conversation->id,
                'inbox_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        DB::transaction(function () use ($conversation, $message, $result, $savedReply) {
            $message->forceFill([
                'delivery_status' => MessageDeliveryStatus::Sent,
                'delivered_at' => $result->deliveredAt ?? now(),
                'external_message_id' => $result->externalId,
                'vendor_comment_id' => $result->vendorCommentId,
            ])->save();

            $conversation->forceFill([
                'is_reply_needed' => false,
                'first_response_at' => $conversation->first_response_at ?? now(),
                'last_message_at' => $message->posted_at,
            ])->save();

            $savedReply?->increment('usage_count');
        });

        Log::info('Inbox reply sent', [
            'org_id' => $conversation->org_id,
            'inbox_conversation_id' => $conversation->id,
            'inbox_message_id' => $message->id,
        ]);

        return $message;
    }

    /**
     * The network comment id to reply under: the most recent inbound comment, falling back to the
     * thread's root comment id.
     */
    private function parentCommentId(InboxConversation $conversation): string
    {
        $latest = $conversation->messages()
            ->where('direction', MessageDirection::Inbound)
            ->whereNotNull('external_message_id')
            ->orderByDesc('posted_at')
            ->value('external_message_id');

        return is_string($latest) && $latest !== '' ? $latest : $conversation->external_conversation_id;
    }
}
