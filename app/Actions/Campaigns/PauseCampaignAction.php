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

/**
 * Step C-6.1 — pause a live campaign: it moves to Paused and every **Scheduled** post in it goes
 * back to Approved through the normal transition (leaving `scheduled` clears the post's dispatch
 * id — the I-1.2 stale-job guard — so a queued publish job no-ops). Publishing, published and
 * failed posts are never touched.
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
                ->where('status', PostStatus::Scheduled)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($posts as $post) {
                try {
                    ($this->transitionPost)($post, PostStatus::Approved, $user, 'Campaign paused.');
                    $paused[] = $post->id;
                } catch (InvalidPostTransitionException) {
                    // Started publishing in the meantime — leave it alone.
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
