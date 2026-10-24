<?php

namespace App\Support\Inbox;

use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Step M-9.2 — shapes an inbox conversation for the frontend. The triage row and the open thread
 * share the conversation core; the thread adds its messages and the original post's context. No
 * internal-only field and no other client's data ever crosses into a prop.
 */
class InboxPresenter
{
    /**
     * A row in the triage list.
     *
     * @return array<string, mixed>
     */
    public static function row(InboxConversation $conversation): array
    {
        $latest = $conversation->latestMessage;

        return [
            'id' => $conversation->id,
            'client' => ['id' => $conversation->client_id, 'name' => $conversation->client?->name],
            'platform' => $conversation->platform->value,
            'platform_label' => $conversation->platform->label(),
            'author' => [
                'name' => $conversation->author_name,
                'handle' => $conversation->author_handle,
                'avatar_url' => $conversation->author_avatar_url,
            ],
            'sentiment' => $conversation->sentiment->value,
            'status' => $conversation->status->value,
            'assignee' => $conversation->assignee ? ['id' => $conversation->assignee->id, 'name' => $conversation->assignee->name] : null,
            'is_reply_needed' => $conversation->is_reply_needed,
            'snippet' => $latest !== null ? Str::limit($latest->body, 120) : null,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'last_inbound_at' => $conversation->last_inbound_at?->toIso8601String(),
            'snoozed_until' => $conversation->snoozed_until?->toIso8601String(),
        ];
    }

    /**
     * The open conversation: the thread plus the original post's context and the viewer's abilities.
     *
     * @return array<string, mixed>
     */
    public static function detail(InboxConversation $conversation, User $user): array
    {
        $target = $conversation->postTarget;
        $post = $target?->post;

        return [
            ...self::row($conversation),
            'permalink' => $conversation->permalink,
            'resolved_at' => $conversation->resolved_at?->toIso8601String(),
            'first_response_at' => $conversation->first_response_at?->toIso8601String(),
            'post' => $post === null ? null : [
                'id' => $post->id,
                'caption' => Str::limit((string) $post->master_caption, 280),
                'thumbnail_url' => $post->assets->first()?->thumbnailUrl(),
            ],
            'messages' => $conversation->messages->map(fn (InboxMessage $message) => self::message($message))->values()->all(),
            'can' => [
                'reply' => $user->can('reply', $conversation),
                'act' => $user->can('act', $conversation),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function message(InboxMessage $message): array
    {
        return [
            'id' => $message->id,
            'direction' => $message->direction->value,
            'author_name' => $message->author_name,
            'author_handle' => $message->author_handle,
            'body' => $message->body,
            'sentiment' => $message->sentiment?->value,
            'posted_at' => $message->posted_at?->toIso8601String(),
            'delivery_status' => $message->delivery_status?->value,
            'delivery_error' => $message->delivery_error,
            'sent_by' => $message->sender?->name,
        ];
    }
}
