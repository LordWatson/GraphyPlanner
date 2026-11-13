<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\StoryHandoffOutcome;
use App\Enums\StoryHandoffRecipient;
use App\Models\Scopes\OrganizationScope;
use App\Services\Publishing\PlatformCapabilities;
use App\Support\Publishing\CaptionComposer;
use App\Support\Publishing\FormatSpec;
use App\Support\Publishing\StoryExtras;
use Database\Factories\PostTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $post_id
 * @property int $social_account_id
 * @property Carbon|null $scheduled_local_date
 * @property string|null $scheduled_local_time
 * @property Carbon|null $scheduled_at_utc
 * @property Carbon|null $reminder_sent_at
 * @property Carbon|null $push_reminder_sent_at
 * @property PostTargetStatus $status
 * @property string|null $external_post_id
 * @property string|null $error
 * @property string|null $publish_attempt_id
 * @property Carbon|null $publish_requested_at
 * @property string|null $publish_correlation_id
 * @property string|null $caption_override
 * @property array<int, string>|null $hashtags_override
 * @property string|null $first_comment
 * @property string|null $link_url
 * @property string|null $format
 * @property array<string, mixed>|null $story_extras
 * @property bool $handoff_required
 * @property StoryHandoffRecipient|null $handoff_recipient
 * @property string|null $vendor_request_id
 * @property string|null $platform_post_id
 * @property string|null $post_url
 * @property Carbon|null $published_at
 * @property Carbon|null $metrics_synced_at
 * @property string|null $metrics_error
 * @property int|null $latest_metrics_id
 * @property-read PostTargetMetric|null $latestMetrics
 * @property-read StoryHandoff|null $openStoryHandoff
 * @property-read StoryHandoff|null $postedStoryHandoff
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'post_id', 'social_account_id', 'scheduled_local_date', 'scheduled_local_time', 'scheduled_at_utc',
    'reminder_sent_at', 'push_reminder_sent_at', 'status', 'external_post_id', 'error', 'publish_attempt_id', 'publish_requested_at', 'publish_correlation_id',
    'caption_override', 'hashtags_override', 'first_comment', 'link_url', 'format',
    'story_extras', 'handoff_required', 'handoff_recipient',
    'vendor_request_id', 'platform_post_id', 'post_url', 'published_at', 'metrics_synced_at', 'metrics_error', 'latest_metrics_id',
])]
class PostTarget extends Model
{
    /** @use HasFactory<PostTargetFactory> */
    use HasFactory;

    /**
     * Step S-5.1: what `handoffState()` reads, besides the target's `post`. Eager-load these
     * wherever channel states are listed (`targets.openStoryHandoff`, …) — lazy loading is off.
     *
     * @var list<string>
     */
    public const HANDOFF_STATE_RELATIONS = ['openStoryHandoff', 'postedStoryHandoff'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_local_date' => 'date',
            'scheduled_at_utc' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'push_reminder_sent_at' => 'datetime',
            'publish_requested_at' => 'datetime',
            'published_at' => 'datetime',
            'metrics_synced_at' => 'datetime',
            'status' => PostTargetStatus::class,
            'hashtags_override' => 'array',
            'story_extras' => 'array',
            'handoff_required' => 'boolean',
            'handoff_recipient' => StoryHandoffRecipient::class,
        ];
    }

    /**
     * Always `HH:MM`, whichever database is underneath.
     *
     * This is the one real `TIME` column in the schema. MySQL hands a `TIME` back as `09:00:00`;
     * SQLite returns the `09:00` that was written. The value is read straight through to the
     * frontend (`BuildCalendarOccurrencesAction` → `occurrences.*.account_local.time`), copied onto
     * grid tiles, and compared against times built with `format('H:i')`, so without normalising it
     * the calendar and post editor showed seconds in production and never in a test — and
     * `grid_tiles.planned_local_time` is `string(8)` precisely because somebody sized it for the
     * longer form rather than noticing why it was longer.
     *
     * Reads only: a write still stores whatever it was given, which is already `H:i` everywhere.
     *
     * @return Attribute<string|null, never>
     */
    protected function scheduledLocalTime(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : substr($value, 0, 5),
        );
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<SocialAccount, $this>
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * Step M-3.1: the newest engagement reading for this channel (null until the first sync).
     *
     * @return BelongsTo<PostTargetMetric, $this>
     */
    public function latestMetrics(): BelongsTo
    {
        return $this->belongsTo(PostTargetMetric::class, 'latest_metrics_id');
    }

    /**
     * @return HasMany<PostTargetMetric, $this>
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(PostTargetMetric::class);
    }

    /**
     * Step I-3.6 / M-2.5: the vendor accepted this target with a future `scheduled_date` and is
     * holding it — it hasn't gone out, and can still be cancelled (or must be, before the post
     * changes, so the old version never publishes alongside the new one).
     */
    public function isVendorHeld(): bool
    {
        return $this->status === PostTargetStatus::Pending
            && filled($this->external_post_id)
            && $this->scheduled_at_utc !== null
            && $this->scheduled_at_utc->isFuture();
    }

    /**
     * Step S-2.5: an assisted Story that has been handed to a person and not confirmed yet —
     * `pending` on a publish attempt, with nothing sent to the vendor. Like a vendor-held channel
     * it hasn't gone out, as far as we know, so its post can still be taken off the schedule.
     * Only meaningful on a `publishing` post (a `scheduled` one has no attempt id yet).
     */
    public function isAwaitingHandoff(): bool
    {
        return $this->handoff_required
            && $this->status === PostTargetStatus::Pending
            && blank($this->external_post_id)
            && filled($this->publish_attempt_id);
    }

    /**
     * Step I-3.1 / M-2.2: the caption this channel publishes — its override, else the master.
     */
    public function effectiveCaption(): string
    {
        return (string) ($this->caption_override ?? $this->post->master_caption ?? '');
    }

    /**
     * The hashtags this channel publishes (bare tags) — its override (an empty list means none
     * here), else the master.
     *
     * @return list<string>
     */
    public function effectiveHashtags(): array
    {
        $tags = $this->hashtags_override ?? $this->post->hashtags ?? [];

        return array_values(array_filter(array_map(fn ($tag) => ltrim(trim((string) $tag), '#'), $tags), fn ($tag) => $tag !== ''));
    }

    public function effectiveFirstComment(): ?string
    {
        $comment = $this->first_comment ?? $this->post->first_comment;

        return filled($comment) ? trim((string) $comment) : null;
    }

    public function effectiveLink(): ?string
    {
        $link = $this->link_url ?? $this->post->link_url;

        return filled($link) ? trim((string) $link) : null;
    }

    /**
     * Whether this channel differs from the master content in any way (the editor's dot).
     */
    public function isCustomized(): bool
    {
        return $this->caption_override !== null
            || $this->hashtags_override !== null
            || $this->first_comment !== null
            || $this->link_url !== null;
    }

    /**
     * The caption exactly as it publishes here (caption + link + hashtags, per the registry).
     */
    public function publishedCaption(): string
    {
        $platform = $this->socialAccount?->platform;

        if ($platform === null) {
            return $this->effectiveCaption();
        }

        return CaptionComposer::compose($platform->spec(), $this->effectiveCaption(), $this->effectiveHashtags(), $this->effectiveLink());
    }

    /**
     * Step I-3.2 / M-2.3: the format this target publishes as — its explicit choice when the
     * platform offers it, else inferred from the post's media (`PlatformCapabilities`).
     */
    public function resolvedFormat(): ?FormatSpec
    {
        $platform = $this->socialAccount?->platform;

        if ($platform === null || $this->post === null) {
            return null;
        }

        return app(PlatformCapabilities::class)->resolveFormat($platform, $this->format, $this->post->mediaKinds());
    }

    /**
     * Step S-2.2: every attempt at posting this Story by hand.
     *
     * @return HasMany<StoryHandoff, $this>
     */
    public function storyHandoffs(): HasMany
    {
        return $this->hasMany(StoryHandoff::class);
    }

    /**
     * The handoff a poster can still act on, if there is one. Eager-load this wherever channel
     * states are listed (`handoffState()` reads it).
     *
     * @return HasOne<StoryHandoff, $this>
     */
    public function openStoryHandoff(): HasOne
    {
        return $this->hasOne(StoryHandoff::class)
            ->ofMany(['id' => 'max'], fn ($query) => $query->whereNull('completed_at')->whereNull('outcome'));
    }

    /**
     * The handoff that ended with somebody confirming the Story went out, if there is one.
     *
     * @return HasOne<StoryHandoff, $this>
     */
    public function postedStoryHandoff(): HasOne
    {
        return $this->hasOne(StoryHandoff::class)
            ->ofMany(['id' => 'max'], fn ($query) => $query->where('outcome', StoryHandoffOutcome::Posted));
    }

    /**
     * Step S-1.2: this channel's Story extras (a link, a poll, …), normalised. Empty for a channel
     * that has none.
     */
    public function storyExtras(): StoryExtras
    {
        return StoryExtras::fromArray($this->story_extras);
    }

    /**
     * Step S-1.2 (decision D3): whether this channel is posted by hand — a Story, on a platform the
     * assisted lane is offered on, with at least one extra. `handoff_required` is the stored copy
     * of this answer, written only by `SyncTargetStoryExtrasAction`. The Story format is only ever
     * an explicit choice, so this never depends on the attached media.
     */
    public function needsHandoff(): bool
    {
        $platform = $this->socialAccount?->platform;

        return $platform !== null
            && $this->format === PlatformCapabilities::STORY_FORMAT
            && app(PlatformCapabilities::class)->supportsStoryExtras($platform)
            && ! $this->storyExtras()->isEmpty();
    }

    /**
     * Step S-1.2 (decision D7): who posts this Story — the channel's own choice, else the client's
     * default. A business workspace has no client to hand it to, so it is always the team.
     */
    public function handoffRecipient(): StoryHandoffRecipient
    {
        // Loaded explicitly, so a caller that only has the channel never trips lazy loading.
        $this->loadMissing('post');
        $this->post?->loadMissing('client');
        $client = $this->post?->client;

        // A client loaded with a column list (`client:id,name`) doesn't carry the two columns
        // read here; fetch the real row rather than mistake "not selected" for a value.
        if ($client !== null && ! array_key_exists('is_primary', $client->getAttributes())) {
            $client = Client::query()->withoutGlobalScopes()->find($client->id);
        }

        if ($client === null || $client->is_primary) {
            return StoryHandoffRecipient::Team;
        }

        return $this->handoff_recipient ?? $client->story_handoff_recipient;
    }

    /**
     * Step S-5.1: the assisted lane's state for this channel, or null for a channel that publishes
     * itself. Derived, never stored (decision D2): the channel stays `pending` while a handoff is
     * open, and this is what turns that into something a person can read.
     *
     *  - `needs_hand_posting`: it will be posted by hand at its time;
     *  - `ready_to_post`: its handoff is open and due — somebody should be posting it now;
     *  - `posted_by_hand`: published, and it was a person who confirmed it.
     *
     * Needs `post`, `openStoryHandoff` and `postedStoryHandoff` loaded.
     */
    public function handoffState(): ?string
    {
        if ($this->status === PostTargetStatus::Published) {
            return $this->postedStoryHandoff !== null ? 'posted_by_hand' : null;
        }

        // A draft with extras isn't "waiting to be posted by hand" yet; lists stay calm until the
        // post is on the schedule.
        if (! $this->handoff_required || $this->status !== PostTargetStatus::Pending
            || ! in_array($this->post?->status, [PostStatus::Scheduled, PostStatus::Publishing], true)) {
            return null;
        }

        $open = $this->openStoryHandoff;

        return $open !== null && $open->due_at->lessThanOrEqualTo(now()) && $this->post->status === PostStatus::Publishing
            ? 'ready_to_post'
            : 'needs_hand_posting';
    }
}
