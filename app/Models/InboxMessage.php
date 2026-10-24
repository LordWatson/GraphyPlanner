<?php

namespace App\Models;

use App\Enums\CommentSentiment;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageDirection;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\InboxMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step M-9.1 / M-9.3 — one comment or reply in an {@see InboxConversation}. `inbound` messages come
 * from the public (ingested by `IngestCommentsForTargetAction`); `outbound` messages are replies a
 * human sent through the vendor (`SendInboxReplyAction`), carrying `sent_by` and a delivery status so
 * a failed send can be retried. Append-only, upserted by (conversation, external_message_id).
 *
 * @property int $id
 * @property int $org_id
 * @property int $inbox_conversation_id
 * @property string|null $external_message_id
 * @property string|null $parent_external_id
 * @property MessageDirection $direction
 * @property string|null $author_name
 * @property string|null $author_handle
 * @property string $body
 * @property CommentSentiment|null $sentiment
 * @property int|null $sent_by
 * @property string|null $vendor_comment_id
 * @property MessageDeliveryStatus|null $delivery_status
 * @property string|null $delivery_error
 * @property Carbon|null $posted_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'org_id', 'inbox_conversation_id', 'external_message_id', 'parent_external_id', 'direction',
    'author_name', 'author_handle', 'body', 'sentiment', 'sent_by', 'vendor_comment_id',
    'delivery_status', 'delivery_error', 'posted_at', 'delivered_at',
])]
class InboxMessage extends Model
{
    /** @use HasFactory<InboxMessageFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'sentiment' => CommentSentiment::class,
            'delivery_status' => MessageDeliveryStatus::class,
            'posted_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InboxConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(InboxConversation::class, 'inbox_conversation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by')->withoutGlobalScope(OrganizationScope::class);
    }
}
