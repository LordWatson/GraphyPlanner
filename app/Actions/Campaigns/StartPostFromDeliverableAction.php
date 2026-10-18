<?php

namespace App\Actions\Campaigns;

use App\Actions\Posts\CreatePostAction;
use App\Enums\PostStatus;
use App\Models\CampaignDeliverable;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step C-3.2 — "Start post": turn a planned deliverable into an Idea post in the campaign, with
 * the deliverable's title as a working caption, a target on the client's account for that
 * platform, and the planned date/time on the target. Goes through `CreatePostAction` so the
 * dual-timezone target maths is the same as everywhere else.
 *
 * The deliverable row is locked and re-checked, and `post_id` is unique in the database, so
 * starting twice is rejected however the requests interleave.
 */
class StartPostFromDeliverableAction
{
    public function __construct(private readonly CreatePostAction $createPost) {}

    public function __invoke(CampaignDeliverable $deliverable, User $user, ?int $socialAccountId = null): Post
    {
        $post = DB::transaction(function () use ($deliverable, $user, $socialAccountId) {
            /** @var CampaignDeliverable $locked */
            $locked = CampaignDeliverable::query()->whereKey($deliverable->id)->lockForUpdate()->firstOrFail();

            if ($locked->post_id !== null) {
                throw ValidationException::withMessages(['deliverable' => 'This deliverable already has a post.']);
            }

            $campaign = $locked->campaign;
            $account = $this->account($locked, $socialAccountId);

            $post = ($this->createPost)($campaign->client, [
                'status' => PostStatus::Idea,
                'campaign_id' => $campaign->id,
                'created_by' => $user->id,
                'master_caption' => $locked->title,
                'targets' => $account === null ? [] : [[
                    'social_account_id' => $account->id,
                    'scheduled_local_date' => $locked->planned_for?->toDateString(),
                    'scheduled_local_time' => $locked->planned_time,
                ]],
            ]);

            $locked->update(['post_id' => $post->id]);
            $campaign->recordActivity('deliverable.started', $user, meta: ['deliverable_id' => $locked->id, 'post_id' => $post->id]);

            return $post;
        });

        Log::info('Post started from campaign deliverable', [
            'campaign_id' => $deliverable->campaign_id,
            'deliverable_id' => $deliverable->id,
            'post_id' => $post->id,
            'user_id' => $user->id,
        ]);

        $deliverable->refresh();

        return $post;
    }

    /**
     * The client's account for the deliverable's platform: the one chosen, or the only one. With
     * several and no choice, the caller must ask (`social_account_id` is required then).
     */
    private function account(CampaignDeliverable $deliverable, ?int $socialAccountId): ?SocialAccount
    {
        $accounts = SocialAccount::query()->withoutOrgScope()
            ->where('client_id', $deliverable->campaign->client_id)
            ->where('platform', $deliverable->platform)
            ->get();

        if ($socialAccountId !== null) {
            $account = $accounts->firstWhere('id', $socialAccountId);

            if ($account === null) {
                throw ValidationException::withMessages(['social_account_id' => 'Pick one of this client’s '.$deliverable->platform->label().' accounts.']);
            }

            return $account;
        }

        if ($accounts->count() > 1) {
            throw ValidationException::withMessages(['social_account_id' => 'This client has several '.$deliverable->platform->label().' accounts. Pick one.']);
        }

        return $accounts->first();
    }
}
