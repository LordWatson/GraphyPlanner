<?php

namespace App\Actions\Stories;

use App\Enums\AssetType;
use App\Enums\StoryHandoffOutcome;
use App\Models\Asset;
use App\Models\StoryHandoff;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Step S-4.1 — a token opens one Story's handoff, and nothing else.
 *
 * `resolve()` is the single gate every handoff route goes through (the page, the media, and the
 * three writes). A token that is unknown, past its lifetime, or for a handoff that was cancelled
 * or superseded gets the same 404 as an expired review link — never a hint about whether it ever
 * existed (decision D6).
 *
 * `payload()` is everything the page is given, and it is deliberately short: the account, the
 * client's name, the time, the extras to add and the media to share. No caption (a Story has
 * none), no other channel, no other post, nothing about money, no internal comment. A handoff that
 * has ended still opens, read-only, so a second teammate sees it was already posted rather than
 * posting it again.
 */
class ShowStoryHandoffAction
{
    public function __construct(private readonly CompleteStoryHandoffAction $complete = new CompleteStoryHandoffAction) {}

    /**
     * @throws NotFoundHttpException
     */
    public function resolve(string $token): StoryHandoff
    {
        // Possession of the link is the authorisation, so whoever happens to be signed in must
        // not change what it finds.
        /** @var StoryHandoff|null $handoff */
        $handoff = StoryHandoff::query()
            ->withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $token))
            ->with(['post.organization', 'post.assets', 'client', 'target.socialAccount', 'user', 'completedBy'])
            ->first();

        if ($handoff === null
            || $handoff->post === null
            || $handoff->target === null
            || $handoff->target->socialAccount === null
            // Step I-1.5: links for an archived client stop working until it is restored.
            || $handoff->client === null || $handoff->client->trashed()
            || $handoff->outcome === StoryHandoffOutcome::Cancelled
            || now()->greaterThan($handoff->linkExpiresAt())
            // Superseded by a retry or a recall the sweep has not closed yet.
            || ($handoff->isOpen() && ! $handoff->isCurrent())) {
            throw new NotFoundHttpException;
        }

        $handoff->target->setRelation('post', $handoff->post);

        return $handoff;
    }

    /**
     * Stamp the first view. Open handoffs only: looking at a finished one is not "opening" it.
     */
    public function markOpened(StoryHandoff $handoff): void
    {
        if ($handoff->opened_at !== null || ! $handoff->isOpen()) {
            return;
        }

        $handoff->forceFill(['opened_at' => now()])->save();

        Log::info('Story handoff opened by its poster', $this->context($handoff));
    }

    /**
     * Stamp the first tap on Share / Save media, which is what stops the nudge.
     */
    public function markShared(StoryHandoff $handoff): void
    {
        if ($handoff->shared_at !== null || ! $handoff->isOpen()) {
            return;
        }

        $handoff->forceFill(['shared_at' => now()])->save();

        Log::info('Story handoff media shared', $this->context($handoff));
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(StoryHandoff $handoff, string $token, ?User $viewer = null): array
    {
        $target = $handoff->target;
        $post = $handoff->post;
        $account = $target?->socialAccount;

        if ($target === null || $post === null || $account === null) {
            throw new NotFoundHttpException;
        }

        $extras = $target->storyExtras();
        $due = $handoff->due_at->copy()->setTimezone($account->timezone ?: (string) config('app.timezone'));
        $grace = max(0, (int) config('publishing.story_handoff.late_grace_minutes', 10));
        $state = $this->state($handoff);
        $canOpenPost = $viewer !== null && $viewer->org_id === $post->org_id && $viewer->can('view', $post);

        return [
            'token' => $token,
            'handoff' => [
                'state' => $state,
                'late' => $state === 'ready' && now()->greaterThan($handoff->due_at->copy()->addMinutes($grace)),
                'early' => $state === 'ready' && $handoff->due_at->isFuture(),
                'due_at' => $handoff->due_at->toIso8601String(),
                'due' => ['date' => $due->toDateString(), 'time' => $due->format('H:i'), 'timezone' => $due->getTimezone()->getName()],
                'client' => ['name' => $handoff->client?->name],
                'account' => [
                    'platform' => $account->platform->value,
                    'platform_label' => $account->platform->label(),
                    'handle' => $account->handle,
                ],
                'posted_by' => $handoff->recipient_type->value,
                'poster_name' => $handoff->user?->name,
                'extras' => $extras->checklist(),
                // What goes on the clipboard with the share: the sticker link, when there is one.
                'link' => $extras->hasValidLink() ? ($extras->link['url'] ?? null) : null,
                'placement_note' => $extras->placementNote,
                'media' => $this->media($post->publishableMedia()->first(), $token),
                'completed' => $handoff->completed_at === null ? null : [
                    'at' => $handoff->completed_at->toIso8601String(),
                    'by' => $handoff->completedBy?->name,
                ],
                // A Story marked as missed can still be confirmed while its channel is untouched.
                'can_confirm_late' => $state === 'missed' && $this->complete->canConfirmLate($handoff, $target, $post),
            ],
            'can' => ['openPost' => $canOpenPost],
            'postUrl' => $canOpenPost ? route('posts.edit', $post->id) : null,
        ];
    }

    private function state(StoryHandoff $handoff): string
    {
        return match ($handoff->outcome) {
            null => 'ready',
            StoryHandoffOutcome::Posted => 'posted',
            StoryHandoffOutcome::Missed => 'missed',
            StoryHandoffOutcome::PublishedPlain => 'published_plain',
            StoryHandoffOutcome::Problem => 'problem',
            StoryHandoffOutcome::Cancelled => 'cancelled',
        };
    }

    /**
     * The one item a Story sends, as the page needs it. The URL is the token-gated media route —
     * never a signed storage URL — and `shareable` is whether it is small enough to hold in memory
     * for the share sheet.
     *
     * @return array<string, mixed>|null
     */
    private function media(?Asset $asset, string $token): ?array
    {
        if ($asset === null || ! GetStoryHandoffMediaAction::isServable($asset)) {
            return null;
        }

        $isVideo = $asset->type === AssetType::Video;
        $maxBytes = max(1, (int) config('publishing.story_handoff.max_share_mb', 50)) * 1024 * 1024;
        $mime = GetStoryHandoffMediaAction::mimeFor($asset);

        return [
            'kind' => $isVideo ? 'video' : 'image',
            'url' => route('story-handoff.media', ['token' => $token]),
            'mime_type' => $mime,
            'filename' => GetStoryHandoffMediaAction::filenameFor($asset),
            'size' => $asset->size,
            'shareable' => $asset->size === null || $asset->size <= $maxBytes,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function context(StoryHandoff $handoff): array
    {
        return [
            'org_id' => $handoff->org_id,
            'post_id' => $handoff->post_id,
            'post_target_id' => $handoff->post_target_id,
            'story_handoff_id' => $handoff->id,
        ];
    }
}
