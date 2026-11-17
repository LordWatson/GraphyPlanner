<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\StoryHandoffOutcome;
use App\Enums\StoryHandoffRecipient;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\ClientAccessScope;
use App\Models\Scopes\OrganizationScope;
use Carbon\CarbonInterface;
use Database\Factories\StoryHandoffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step S-2.2 — one attempt at posting an assisted Story by hand (`story-extras.md`).
 *
 * Instagram's API cannot add a link or a sticker to a Story, so a Story channel with extras is
 * never sent to the vendor. When its post starts publishing it gets one of these instead: the
 * record of who was asked, when, whether they opened it, and how it ended. The channel itself
 * stays `pending` until then (decision D2), so the post waits in `publishing` exactly as it does
 * for a vendor.
 *
 * A handoff belongs to one publish attempt (D5). Only the hash of its link's token is stored (D6);
 * the token itself is derived when a link is needed (`plainToken()`,
 * `IssueStoryHandoffLinkAction`), so it is the same link every time for one attempt.
 *
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property int $post_id
 * @property int $post_target_id
 * @property string $publish_attempt_id
 * @property string $token_hash
 * @property StoryHandoffRecipient $recipient_type
 * @property int|null $user_id
 * @property Carbon $due_at
 * @property Carbon|null $notified_at
 * @property Carbon|null $nudged_at
 * @property Carbon|null $opened_at
 * @property Carbon|null $shared_at
 * @property Carbon|null $completed_at
 * @property int|null $completed_by_user_id
 * @property string|null $completed_via
 * @property StoryHandoffOutcome|null $outcome
 * @property string|null $problem_note
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Post|null $post
 * @property-read PostTarget|null $target
 * @property-read Client|null $client
 * @property-read User|null $user
 * @property-read User|null $completedBy
 */
#[Fillable([
    'org_id', 'client_id', 'post_id', 'post_target_id', 'publish_attempt_id', 'token_hash',
    'recipient_type', 'user_id', 'due_at', 'notified_at', 'nudged_at', 'opened_at', 'shared_at',
    'completed_at', 'completed_by_user_id', 'completed_via', 'outcome', 'problem_note', 'expires_at',
])]
#[Hidden(['token_hash'])]
class StoryHandoff extends Model
{
    /** @use HasFactory<StoryHandoffFactory> */
    use BelongsToOrganization, HasFactory;

    /** Confirmed from the handoff page (the emailed / pushed link). */
    public const VIA_LINK = 'link';

    /** Confirmed by a member from the post editor ("Mark as posted"). */
    public const VIA_STAFF = 'staff';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipient_type' => StoryHandoffRecipient::class,
            'outcome' => StoryHandoffOutcome::class,
            'due_at' => 'datetime',
            'notified_at' => 'datetime',
            'nudged_at' => 'datetime',
            'opened_at' => 'datetime',
            'shared_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Handoffs nobody has closed yet.
     *
     * @param  Builder<StoryHandoff>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('completed_at'))->whereNull($query->qualifyColumn('outcome'));
    }

    public function isOpen(): bool
    {
        return $this->completed_at === null && $this->outcome === null;
    }

    /**
     * Whether this handoff is still the live one for its channel: the channel is on the same
     * publish attempt and still waiting, and the post is still going out. A retry, a recall or an
     * unschedule makes it stale (decision D5) — its link stops working and the sweep closes it.
     */
    public function isCurrent(): bool
    {
        $target = $this->target;
        $post = $this->post;

        return $target !== null
            && $post !== null
            && $target->publish_attempt_id === $this->publish_attempt_id
            && $target->status === PostTargetStatus::Pending
            && $target->external_post_id === null
            && $post->status === PostStatus::Publishing;
    }

    /**
     * Inside a transaction: lock this handoff together with its post and channel, in the order
     * every Story action uses (post, channel, handoff), and return it with both relations set to
     * the locked rows. Null when any of the three has gone.
     *
     * The access scopes are lifted on purpose: the caller already holds the handoff (by its token,
     * or from a policy-checked route), and whoever is signed in must not change whether the row
     * can be found.
     */
    public static function lockForChange(int $id): ?self
    {
        /** @var self|null $handoff */
        $handoff = self::query()->withoutGlobalScopes()->find($id);

        if ($handoff === null) {
            return null;
        }

        /** @var Post|null $post */
        $post = Post::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->withoutGlobalScope(ClientAccessScope::class)
            ->whereKey($handoff->post_id)
            ->lockForUpdate()
            ->first();

        /** @var PostTarget|null $target */
        $target = PostTarget::query()->whereKey($handoff->post_target_id)->lockForUpdate()->first();

        /** @var self|null $locked */
        $locked = self::query()->withoutGlobalScopes()->whereKey($id)->lockForUpdate()->first();

        if ($post === null || $target === null || $locked === null) {
            return null;
        }

        $target->setRelation('post', $post);

        return $locked->setRelation('post', $post)->setRelation('target', $target);
    }

    /**
     * How long after the due time the link still opens (`publishing.story_handoff.token_ttl_hours`).
     * Read-only once the handoff is closed; after this it is gone.
     */
    public function linkExpiresAt(): CarbonInterface
    {
        // From whichever is later, the Story's time or the moment this attempt was opened: a
        // Story missed on Monday and retried on Wednesday must get a link that still opens.
        $from = $this->created_at !== null ? $this->due_at->max($this->created_at) : $this->due_at;

        return $from->copy()->addHours(max(1, (int) config('publishing.story_handoff.token_ttl_hours', 30)));
    }

    /**
     * The link's token (decision D6, as built). Derived, never stored: an HMAC of this handoff's
     * id and publish attempt under the app key, so the same link can be handed out again — to
     * every teammate, in the nudge, from the bell — without keeping the token anywhere, and
     * without one person's link replacing another's. Only its sha256 is at rest (`token_hash`).
     * A new publish attempt is a new handoff, and so a new token.
     */
    public function plainToken(): string
    {
        return hash_hmac('sha256', 'story-handoff|'.$this->id.'|'.$this->publish_attempt_id, (string) config('app.key'));
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
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<PostTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class, 'post_target_id');
    }

    /**
     * The team member asked to post it, when there is one (null = everyone, or the client).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
