<?php

namespace App\Actions\Campaigns;

use App\Actions\Posts\PostStatusTransitionMap;
use App\Actions\Posts\SendClientReviewRequestedEmailAction;
use App\Actions\Posts\TransitionPostStatusAction;
use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Enums\PostStatus;
use App\Exceptions\Posts\InvalidPostTransitionException;
use App\Mail\CampaignPostsReadyMail;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\ReviewToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Step C-4.6 — bulk actions on a selection of a campaign's posts: submit drafts for internal
 * review, send to the client, approve, move between phases, reassign.
 *
 * Each status change goes through `TransitionPostStatusAction` (state machine, row lock, logs,
 * notifications) and is checked against `PostPolicy::transition` for the actor first; anything
 * that can't move is skipped and reported with the reason (the I-4.1 pattern). "Send to client"
 * suppresses the per-post email and sends the client **one** email for the whole batch.
 */
class BulkUpdateCampaignPostsAction
{
    public const SUBMIT = 'submit';

    public const SEND_TO_CLIENT = 'send_to_client';

    public const APPROVE = 'approve';

    public const PHASE = 'phase';

    public const ASSIGN = 'assign';

    public const ACTIONS = [self::SUBMIT, self::SEND_TO_CLIENT, self::APPROVE, self::PHASE, self::ASSIGN];

    public function __construct(private readonly TransitionPostStatusAction $transition) {}

    /**
     * @param  list<int>  $postIds
     * @return array{done: list<int>, skipped: list<array{id: int, reason: string}>}
     */
    public function __invoke(Campaign $campaign, User $user, string $action, array $postIds, ?int $value = null): array
    {
        $posts = $campaign->posts()->whereIn('id', $postIds)->with('deliverable')->get()->keyBy('id');
        $done = [];
        $skipped = [];

        foreach ($postIds as $id) {
            $post = $posts->get($id);

            if ($post === null) {
                $skipped[] = ['id' => $id, 'reason' => 'Not part of this campaign.'];

                continue;
            }

            $reason = match ($action) {
                self::PHASE, self::ASSIGN => $this->updateDeliverable($post, $action, $value),
                default => $this->move($post, $this->target($action), $user),
            };

            if ($reason === null) {
                $done[] = $post->id;
            } else {
                $skipped[] = ['id' => $post->id, 'reason' => $reason];
            }
        }

        if ($action === self::SEND_TO_CLIENT && $done !== []) {
            $this->emailClientOnce($campaign, $done);
        }

        if ($done !== []) {
            $campaign->recordActivity('posts.bulk', $user, meta: ['action' => $action, 'post_ids' => $done, 'skipped' => count($skipped)]);
        }

        Log::info('Campaign bulk post action', [
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'action' => $action,
            'done' => count($done),
            'skipped' => count($skipped),
        ]);

        return ['done' => $done, 'skipped' => $skipped];
    }

    private function target(string $action): PostStatus
    {
        return match ($action) {
            self::SUBMIT => PostStatus::InternalReview,
            self::SEND_TO_CLIENT => PostStatus::WaitingClient,
            default => PostStatus::Approved,
        };
    }

    private function move(Post $post, PostStatus $to, User $user): ?string
    {
        if ($post->status === $to) {
            return 'Already '.mb_strtolower($to->label()).'.';
        }

        if (! PostStatusTransitionMap::isAllowed($post->status, $to)) {
            return 'A '.mb_strtolower($post->status->label()).' post can’t move to '.mb_strtolower($to->label()).'.';
        }

        if (! $user->can('transition', [$post, $to])) {
            return 'You can’t make that move.';
        }

        try {
            ($this->transition)(
                $post,
                $to,
                $user,
                sendClientReviewRequestedEmail: new class extends SendClientReviewRequestedEmailAction
                {
                    // The batch sends one email instead (see `emailClientOnce`).
                    public function __invoke(Post $post, CreateReviewTokenAction $createReviewToken = new CreateReviewTokenAction): ?ReviewToken
                    {
                        return null;
                    }
                },
            );
        } catch (InvalidPostTransitionException) {
            return 'It changed while you were working on it.';
        }

        return null;
    }

    private function updateDeliverable(Post $post, string $action, ?int $value): ?string
    {
        if ($post->deliverable === null) {
            return 'Not started from the plan, so it has no phase or assignee.';
        }

        $post->deliverable->update([$action === self::PHASE ? 'campaign_phase_id' : 'assignee_id' => $value]);

        return null;
    }

    /**
     * @param  list<int>  $postIds
     */
    private function emailClientOnce(Campaign $campaign, array $postIds): void
    {
        $email = $campaign->client->approval_email;

        if (blank($email)) {
            Log::warning('Skipped batch review email — no approval_email on file', ['campaign_id' => $campaign->id]);

            return;
        }

        DB::afterCommit(fn () => Mail::to((string) $email)->queue(new CampaignPostsReadyMail($campaign, count($postIds))));
    }
}
