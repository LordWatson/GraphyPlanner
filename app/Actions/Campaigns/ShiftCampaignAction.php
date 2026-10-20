<?php

namespace App\Actions\Campaigns;

use App\Actions\Posts\ReschedulePostAction;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\CampaignPhase;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Services\Publishing\VendorScheduleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step C-6.2 — move a whole campaign by N days: its dates, phases, planned deliverables and every
 * not-yet-published post. Posts go through `ReschedulePostAction`, so each target keeps its own
 * local wall-clock time in its own timezone (the dual-timezone model) and a scheduled post is
 * re-dispatched for its new time.
 *
 * `preview()` lists what moves and what can't (published or publishing posts; targets that would
 * land in the past) before anything is written; `apply()` does it in one transaction.
 */
class ShiftCampaignAction
{
    /**
     * Posts that have gone (or are going) out stay where they are.
     */
    private const FIXED = [PostStatus::Publishing, PostStatus::Published, PostStatus::PartiallyPublished, PostStatus::Archived];

    public function __construct(
        private readonly ReschedulePostAction $reschedule,
        private readonly VendorScheduleService $vendor,
    ) {}

    /**
     * @return array{days: int, campaign: array{from: string|null, to: string|null}, phases: int, deliverables: int, posts: list<array{id: int, caption: string|null}>, blocked: list<array{id: int, caption: string|null, reason: string}>}
     */
    public function preview(Campaign $campaign, int $days): array
    {
        $posts = $campaign->posts()->with('targets.socialAccount')->get();
        $movable = [];
        $blocked = [];

        foreach ($posts as $post) {
            $reason = $this->blocker($post, $days);
            $row = ['id' => $post->id, 'caption' => $post->master_caption ? mb_strimwidth(trim($post->master_caption), 0, 80, '…') : null];

            if ($reason === null) {
                $movable[] = $row;
            } else {
                $blocked[] = [...$row, 'reason' => $reason];
            }
        }

        return [
            'days' => $days,
            'campaign' => [
                'from' => $campaign->start_date?->copy()->addDays($days)->toDateString(),
                'to' => $campaign->end_date?->copy()->addDays($days)->toDateString(),
            ],
            'phases' => $campaign->phases()->whereNotNull('starts_on')->count(),
            'deliverables' => $campaign->deliverables()->whereNull('post_id')->whereNotNull('planned_for')->count(),
            'posts' => $movable,
            'blocked' => $blocked,
        ];
    }

    /**
     * @return array{moved: list<int>, blocked: list<array{id: int, caption: string|null, reason: string}>}
     */
    public function apply(Campaign $campaign, int $days, User $user): array
    {
        $plan = $this->preview($campaign, $days);
        $movableIds = array_column($plan['posts'], 'id');

        // Step I-3.6 / M-2.5: posts the vendor is holding for later are recalled *before* the
        // transaction (a rollback can't un-cancel a vendor job), and re-submitted at their new
        // times after it commits. One the vendor won't release stays put, reported as blocked.
        $recalled = [];

        foreach (Post::query()->whereIn('id', $movableIds)->whereIn('status', [PostStatus::Scheduled, PostStatus::Publishing])->get() as $post) {
            try {
                $recalled[$post->id] = $this->vendor->recall($post);
            } catch (ValidationException $e) {
                $movableIds = array_values(array_diff($movableIds, [$post->id]));
                $plan['blocked'][] = ['id' => $post->id, 'caption' => $post->master_caption, 'reason' => 'Already publishing on the platform.'];
            }
        }

        DB::transaction(function () use ($campaign, $days, $user, $movableIds, $plan, $recalled) {
            $campaign->update([
                'start_date' => $campaign->start_date?->copy()->addDays($days),
                'end_date' => $campaign->end_date?->copy()->addDays($days),
            ]);

            $campaign->phases()->whereNotNull('starts_on')->get()->each(fn (CampaignPhase $phase) => $phase->update([
                'starts_on' => $phase->starts_on?->copy()->addDays($days),
                'ends_on' => $phase->ends_on?->copy()->addDays($days),
            ]));

            $campaign->deliverables()->whereNull('post_id')->whereNotNull('planned_for')->get()->each(fn (CampaignDeliverable $deliverable) => $deliverable->update([
                'planned_for' => $deliverable->planned_for?->copy()->addDays($days),
            ]));

            // Started deliverables follow their post's new date.
            $campaign->deliverables()->whereIn('post_id', $movableIds)->whereNotNull('planned_for')->get()->each(fn (CampaignDeliverable $deliverable) => $deliverable->update([
                'planned_for' => $deliverable->planned_for?->copy()->addDays($days),
            ]));

            foreach (Post::query()->whereIn('id', $movableIds)->get() as $post) {
                ($this->reschedule)($post, $days, $user);

                if (isset($recalled[$post->id])) {
                    DB::afterCommit(fn () => $this->vendor->resubmit($post, $recalled[$post->id]));
                }
            }

            $campaign->recordActivity('campaign.shifted', $user, meta: [
                'days' => $days,
                'moved_post_ids' => $movableIds,
                'blocked_post_ids' => array_column($plan['blocked'], 'id'),
            ]);
        });

        Log::info('Campaign shifted', [
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'days' => $days,
            'moved' => count($movableIds),
            'blocked' => count($plan['blocked']),
        ]);

        return ['moved' => $movableIds, 'blocked' => $plan['blocked']];
    }

    private function blocker(Post $post, int $days): ?string
    {
        // Step I-3.6 / M-2.5: still held by the vendor for later — `ReschedulePostAction` recalls
        // and re-submits it.
        if (in_array($post->status, self::FIXED, true) && ! $post->canBeRecalled()) {
            return $post->status === PostStatus::Archived ? 'Archived.' : 'Already out, so it stays put.';
        }

        foreach ($post->targets as $target) {
            if ($this->landsInPast($target, $days)) {
                return 'A channel would land in the past.';
            }
        }

        return null;
    }

    private function landsInPast(PostTarget $target, int $days): bool
    {
        if ($target->scheduled_local_date === null) {
            return false;
        }

        $timezone = $target->socialAccount->timezone;
        $date = $target->scheduled_local_date->copy()->addDays($days)->toDateString();

        return filled($target->scheduled_local_time)
            ? Carbon::parse($date.' '.$target->scheduled_local_time, $timezone)->isPast()
            : $date < Carbon::now($timezone)->toDateString();
    }
}
