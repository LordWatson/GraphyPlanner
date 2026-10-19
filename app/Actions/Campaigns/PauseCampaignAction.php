<?php

namespace App\Actions\Campaigns;

use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\CampaignStatus;
use App\Enums\PostStatus;
use App\Exceptions\Posts\InvalidPostTransitionException;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step C-6.1 — pause a live campaign: it moves to Paused and every **Scheduled** post in it goes
 * back to Approved through the normal transition (leaving `scheduled` clears the post's dispatch
 * id — the I-1.2 stale-job guard — so a queued publish job no-ops). Publishing, published and
 * failed posts are never touched — except (Step I-3.6 / M-2.5) a `publishing` post the vendor is
 * still holding for later, whose held channels are cancelled through the same transition.
 *
 * The posts are locked and re-checked one by one: one that started publishing between the
 * confirm dialog and this call is skipped, not yanked back. The exact set that was paused is
 * recorded in the activity row's meta, which is what Resume restores.
 */
class PauseCampaignAction
{
    public function __construct(
        private readonly TransitionCampaignStatusAction $transition,
        private readonly TransitionPostStatusAction $transitionPost,
    ) {}

    /**
     * @return list<int> the ids of the posts taken off the schedule
     */
    public function __invoke(Campaign $campaign, User $user): array
    {
        $paused = [];

        ($this->transition)($campaign, CampaignStatus::Paused, $user, within: function (Campaign $locked) use ($user, &$paused) {
            $posts = Post::query()->withoutOrgScope()
                ->where('campaign_id', $locked->id)
                ->whereIn('status', [PostStatus::Scheduled, PostStatus::Publishing])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($posts as $post) {
                // Step I-3.6 / M-2.5: a `publishing` post is only paused while the vendor still
                // holds every channel for later — the transition cancels them there first.
                if ($post->status === PostStatus::Publishing && ! $post->canBeRecalled()) {
                    continue;
                }

                try {
                    ($this->transitionPost)($post, PostStatus::Approved, $user, 'Campaign paused.');
                    $paused[] = $post->id;
                } catch (InvalidPostTransitionException|ValidationException) {
                    // Started publishing in the meantime (or the vendor refused to cancel) —
                    // leave it alone.
                }
            }

            return ['paused_post_ids' => $paused, 'paused_at' => now()->toIso8601String()];
        });

        Log::info('Campaign paused', [
            'campaign_id' => $campaign->id,
            'org_id' => $campaign->org_id,
            'user_id' => $user->id,
            'unscheduled' => count($paused),
        ]);

        return $paused;
    }
}
