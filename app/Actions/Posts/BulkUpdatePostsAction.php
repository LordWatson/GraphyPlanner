<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step M-5.1 — bulk actions on a selection of posts from the content hub, across clients. Each post
 * is handled one at a time; anything that can't take the action is skipped and reported with a plain
 * reason — never silently dropped (the I-4.1 pattern, shared with the campaign bulk bar C-4.6).
 *
 * Status moves (and archive) go through `BulkTransitionPostsAction`, which re-checks the state
 * machine and `PostPolicy::transition`; reschedule goes through `ReschedulePostAction` (vendor
 * recall + re-dispatch); adding to a campaign is pinned to the post's own client. Exporting a
 * selection is a download, handled by the controller, not here.
 */
class BulkUpdatePostsAction
{
    public const MOVE = 'move';

    public const ARCHIVE = 'archive';

    public const CAMPAIGN = 'campaign';

    public const RESCHEDULE = 'reschedule';

    public const ASSIGN = 'assign';

    public const ACTIONS = [self::MOVE, self::ARCHIVE, self::CAMPAIGN, self::RESCHEDULE, self::ASSIGN];

    public function __construct(
        private readonly BulkTransitionPostsAction $bulkTransition,
        private readonly ReschedulePostAction $reschedule,
    ) {}

    /**
     * `$value` is the status slug for MOVE, the campaign id (or null to clear) for CAMPAIGN, and the
     * whole number of days to shift for RESCHEDULE; it is ignored for ARCHIVE.
     *
     * @param  list<int>  $postIds
     * @return array{done: list<int>, skipped: list<array{id: int, reason: string}>}
     */
    public function __invoke(User $user, string $action, array $postIds, int|string|null $value = null): array
    {
        $posts = Post::query()
            ->whereIn('id', $postIds)
            ->with(['targets.socialAccount', 'client:id,org_id'])
            ->get()
            ->keyBy('id');

        $campaign = $action === self::CAMPAIGN && $value !== null
            ? Campaign::query()->find((int) $value)
            : null;

        $done = [];
        $skipped = [];

        foreach ($postIds as $id) {
            $post = $posts->get($id);

            if ($post === null) {
                $skipped[] = ['id' => $id, 'reason' => 'No longer available.'];

                continue;
            }

            $reason = match ($action) {
                self::MOVE => $this->move($post, $user, is_string($value) ? $value : null),
                self::ARCHIVE => $this->transition($post, PostStatus::Archived, $user),
                self::CAMPAIGN => $this->setCampaign($post, $user, $campaign, $value === null ? null : (int) $value),
                self::RESCHEDULE => $this->shift($post, $user, is_numeric($value) ? (int) $value : null),
                self::ASSIGN => $this->assign($post, $user, $value === null ? null : (int) $value),
                default => 'Unknown action.',
            };

            if ($reason === null) {
                $done[] = $post->id;
            } else {
                $skipped[] = ['id' => $post->id, 'reason' => $reason];
            }
        }

        Log::info('Content hub bulk action', [
            'org_id' => $user->org_id,
            'user_id' => $user->id,
            'action' => $action,
            'value' => $value,
            'done' => count($done),
            'skipped' => count($skipped),
        ]);

        return ['done' => $done, 'skipped' => $skipped];
    }

    private function move(Post $post, User $user, ?string $status): ?string
    {
        $to = $status !== null ? PostStatus::tryFrom($status) : null;

        if ($to === null) {
            return 'Pick a status to move to.';
        }

        return $this->transition($post, $to, $user);
    }

    private function transition(Post $post, PostStatus $to, User $user): ?string
    {
        return ($this->bulkTransition)([$post], $to, $user)['skipped'][0]['reason'] ?? null;
    }

    private function setCampaign(Post $post, User $user, ?Campaign $campaign, ?int $campaignId): ?string
    {
        if (! $user->can('update', $post)) {
            return 'You can’t edit this post.';
        }

        if ($campaignId === null) {
            $post->update(['campaign_id' => null]);

            return null;
        }

        if ($campaign === null) {
            return 'That campaign no longer exists.';
        }

        if ($campaign->client_id !== $post->client_id) {
            return 'That campaign belongs to another client.';
        }

        $post->update(['campaign_id' => $campaign->id]);

        return null;
    }

    private function assign(Post $post, User $user, ?int $assigneeId): ?string
    {
        if (! $user->can('update', $post)) {
            return 'You can’t edit this post.';
        }

        // A null assignee clears it; otherwise the person must be staff in this org.
        if ($assigneeId !== null && ! User::query()
            ->whereKey($assigneeId)
            ->where('org_id', $post->org_id)
            ->whereIn('role', [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer])
            ->exists()) {
            return 'That person isn’t on this team.';
        }

        $post->update(['assignee_id' => $assigneeId]);

        return null;
    }

    private function shift(Post $post, User $user, ?int $days): ?string
    {
        if ($days === null || $days === 0) {
            return 'Choose how many days to shift by.';
        }

        if (! $user->can('reschedule', $post)) {
            return 'This post can’t be rescheduled.';
        }

        try {
            ($this->reschedule)($post, $days, $user);
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first() ?? 'It couldn’t move.';
        }

        return null;
    }
}
