<?php

namespace App\Actions\Campaigns;

use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Actions\Posts\ReschedulePostAction;
use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\CampaignStatus;
use App\Enums\PostStatus;
use App\Exceptions\Posts\InvalidPostTransitionException;
use App\Models\Campaign;
use App\Models\CampaignActivityLog;
use App\Models\Post;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step C-6.1 — resume a paused campaign. It returns to Active, and the posts the pause took off
 * the schedule (exactly that set, from the pause's activity meta) can go back on:
 *
 * - `keep`: at their original times, where those are still in the future;
 * - `shift`: every one moved later by the number of days the campaign was paused;
 * - `none`: left approved, for the team to schedule by hand.
 *
 * A post that changed since the pause (edited back to draft, deleted, already rescheduled) or
 * whose slot has passed is skipped and reported.
 */
class ResumeCampaignAction
{
    public const KEEP = 'keep';

    public const SHIFT = 'shift';

    public const NONE = 'none';

    public function __construct(
        private readonly TransitionCampaignStatusAction $transition,
        private readonly TransitionPostStatusAction $transitionPost,
        private readonly ReschedulePostAction $reschedule,
        private readonly EvaluatePostChecklistAction $checklist,
    ) {}

    /**
     * @return array{rescheduled: list<int>, skipped: list<array{id: int, reason: string}>, shifted_days: int}
     */
    public function __invoke(Campaign $campaign, User $user, string $mode = self::KEEP): array
    {
        $pause = $this->lastPause($campaign);
        $postIds = array_map('intval', (array) ($pause?->meta['paused_post_ids'] ?? []));
        $pausedAt = isset($pause?->meta['paused_at']) ? CarbonImmutable::parse((string) $pause->meta['paused_at']) : null;
        $days = $pausedAt ? max(0, (int) ceil($pausedAt->diffInHours(now()) / 24)) : 0;

        ($this->transition)($campaign, CampaignStatus::Active, $user);

        $result = ['rescheduled' => [], 'skipped' => [], 'shifted_days' => $mode === self::SHIFT ? $days : 0];

        if ($mode !== self::NONE) {
            foreach (Post::query()->whereIn('id', $postIds)->with('targets.socialAccount')->get() as $post) {
                $reason = $this->reschedulePost($post, $user, $mode === self::SHIFT ? $days : 0);

                if ($reason === null) {
                    $result['rescheduled'][] = $post->id;
                } else {
                    $result['skipped'][] = ['id' => $post->id, 'reason' => $reason];
                }
            }
        }

        $campaign->recordActivity('campaign.resumed', $user, meta: [...$result, 'mode' => $mode]);

        Log::info('Campaign resumed', [
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'mode' => $mode,
            'rescheduled' => count($result['rescheduled']),
            'skipped' => count($result['skipped']),
        ]);

        return $result;
    }

    private function reschedulePost(Post $post, User $user, int $days): ?string
    {
        if ($post->status !== PostStatus::Approved) {
            return 'It changed while the campaign was paused.';
        }

        if ($days > 0) {
            try {
                ($this->reschedule)($post, $days, $user);
            } catch (ValidationException) {
                return 'Its new slot would be in the past.';
            }
        } elseif ($post->targets->contains(fn ($target) => $target->scheduled_at_utc !== null && $target->scheduled_at_utc->isPast())) {
            return 'Its original slot has passed. Pick a new time.';
        }

        if (! ($this->checklist)($post->refresh())['passed']) {
            return 'Its pre-schedule checklist doesn’t pass any more.';
        }

        try {
            ($this->transitionPost)($post, PostStatus::Scheduled, $user, 'Campaign resumed.');
        } catch (InvalidPostTransitionException) {
            return 'It changed while you were resuming.';
        }

        return null;
    }

    private function lastPause(Campaign $campaign): ?CampaignActivityLog
    {
        return $campaign->activityLogs()
            ->where('event', 'status.changed')
            ->where('to', CampaignStatus::Paused->value)
            ->latest('created_at')
            ->latest('id')
            ->first();
    }
}
