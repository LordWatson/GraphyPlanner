<?php

namespace App\Models;

use App\Enums\PostTargetStatus;
use App\Models\Scopes\OrganizationScope;
use App\Services\Publishing\PlatformCapabilities;
use App\Support\Publishing\CaptionComposer;
use App\Support\Publishing\FormatSpec;
use Database\Factories\PostTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $post_id
 * @property int $social_account_id
 * @property Carbon|null $scheduled_local_date
 * @property string|null $scheduled_local_time
 * @property Carbon|null $scheduled_at_utc
 * @property Carbon|null $reminder_sent_at
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'post_id', 'social_account_id', 'scheduled_local_date', 'scheduled_local_time', 'scheduled_at_utc',
    'reminder_sent_at', 'status', 'external_post_id', 'error', 'publish_attempt_id', 'publish_requested_at', 'publish_correlation_id',
    'caption_override', 'hashtags_override', 'first_comment', 'link_url', 'format',
])]
class PostTarget extends Model
{
    /** @use HasFactory<PostTargetFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_local_date' => 'date',
            'scheduled_at_utc' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'publish_requested_at' => 'datetime',
            'status' => PostTargetStatus::class,
            'hashtags_override' => 'array',
        ];
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
}
