<?php

namespace App\Models;

use App\Enums\CommentSentiment;
use App\Enums\InboxStatus;
use App\Enums\Platform;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\InboxConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Step M-9.1 / M-9.2 — one comment thread on a published post target. The thread is the unit a
 * community manager works with: it carries the assignment, the triage sentiment, the status and the
 * SLA stamps, all rolled up from its {@see InboxMessage}s. Created/updated idempotently by
 * `IngestCommentsForTargetAction` from what `CommentSource` reads; replies are sent by
 * `SendInboxReplyAction`. `ClientReviewer` never sees it.
 *
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property int $social_account_id
 * @property int|null $post_target_id
 * @property Platform $platform
 * @property string $external_conversation_id
 * @property string|null $author_name
 * @property string|null $author_handle
 * @property string|null $author_avatar_url
 * @property string|null $permalink
 * @property InboxStatus $status
 * @property CommentSentiment $sentiment
 * @property int|null $assigned_to
 * @property bool $is_reply_needed
 * @property CarbonInterface|null $last_message_at
 * @property CarbonInterface|null $last_inbound_at
 * @property CarbonInterface|null $first_response_at
 * @property CarbonInterface|null $snoozed_until
 * @property CarbonInterface|null $reminder_sent_at
 * @property CarbonInterface|null $resolved_at
 * @property int|null $resolved_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'org_id', 'client_id', 'social_account_id', 'post_target_id', 'platform',
    'external_conversation_id', 'author_name', 'author_handle', 'author_avatar_url', 'permalink',
    'status', 'sentiment', 'assigned_to', 'is_reply_needed', 'last_message_at', 'last_inbound_at',
    'first_response_at', 'snoozed_until', 'reminder_sent_at', 'resolved_at', 'resolved_by',
])]
class InboxConversation extends Model
{
    /** @use HasFactory<InboxConversationFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'status' => InboxStatus::class,
            'sentiment' => CommentSentiment::class,
            'is_reply_needed' => 'boolean',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'first_response_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed()->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<SocialAccount, $this>
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class)->withTrashed()->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<PostTarget, $this>
     */
    public function postTarget(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return HasMany<InboxMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(InboxMessage::class)->orderBy('posted_at')->orderBy('id');
    }

    /**
     * @return HasOne<InboxMessage, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(InboxMessage::class)->latestOfMany('posted_at');
    }

    /**
     * Open or snoozed threads — the ones still wanting attention.
     *
     * @param  Builder<InboxConversation>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', InboxStatus::activeValues());
    }

    /**
     * @param  Builder<InboxConversation>  $query
     */
    public function scopeNeedingReply(Builder $query): void
    {
        $query->where('is_reply_needed', true)->where('status', InboxStatus::Open);
    }
}
