<?php

namespace App\Actions\Posts;

use App\Actions\Portal\CreatePortalLoginTokenAction;
use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Enums\PostStatus;
use App\Exceptions\Posts\InvalidPostTransitionException;
use App\Models\Post;
use App\Models\ReviewToken;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Moves a list of posts to one status, one post at a time through `TransitionPostStatusAction`
 * (state machine, row lock, checklist, logs, notifications) after checking `PostPolicy::transition`
 * for the actor. Anything that can't move is skipped and reported with a plain reason — never
 * skipped silently. Extracted from the campaign bulk action (Step C-4.6) so a grid (Step 18.9),
 * which may have no campaign, shares it.
 *
 * `$oneClientEmail` suppresses the per-post review email when the caller sends the client one
 * email for the whole batch.
 */
class BulkTransitionPostsAction
{
    public function __construct(private readonly TransitionPostStatusAction $transition) {}

    /**
     * @param  iterable<Post>  $posts
     * @return array{done: list<int>, skipped: list<array{id: int, reason: string}>}
     */
    public function __invoke(iterable $posts, PostStatus $to, User $user, bool $oneClientEmail = false): array
    {
        $done = [];
        $skipped = [];

        foreach ($posts as $post) {
            $reason = $this->move($post, $to, $user, $oneClientEmail);

            if ($reason === null) {
                $done[] = $post->id;
            } else {
                $skipped[] = ['id' => $post->id, 'reason' => $reason];
            }
        }

        return ['done' => $done, 'skipped' => $skipped];
    }

    private function move(Post $post, PostStatus $to, User $user, bool $oneClientEmail): ?string
    {
        if ($post->status === $to) {
            return 'Already '.mb_strtolower($to->label()).'.';
        }

        if (PostStatusTransitionMap::needsClientApproval($post, $post->status, $to)) {
            return 'Needs the client’s approval.';
        }

        if (! PostStatusTransitionMap::isAllowedFor($post, $to)) {
            return 'A '.mb_strtolower($post->status->label()).' post can’t move to '.mb_strtolower($to->label()).'.';
        }

        if (! $user->can('transition', [$post, $to])) {
            return 'You can’t make that move.';
        }

        if ($to === PostStatus::Scheduled && ! (new EvaluatePostChecklistAction)($post)['passed']) {
            return 'Its checklist isn’t passing yet.';
        }

        try {
            ($this->transition)(
                $post,
                $to,
                $user,
                sendClientReviewRequestedEmail: $oneClientEmail
                    ? new class extends SendClientReviewRequestedEmailAction
                    {
                        // The caller sends one email for the batch instead.
                        public function __invoke(Post $post, CreateReviewTokenAction $createReviewToken = new CreateReviewTokenAction, CreatePortalLoginTokenAction $createPortalLoginToken = new CreatePortalLoginTokenAction): ?ReviewToken
                        {
                            return null;
                        }
                    }
                : new SendClientReviewRequestedEmailAction,
            );
        } catch (InvalidPostTransitionException) {
            return 'It changed while you were working on it.';
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first() ?? 'It couldn’t move.';
        }

        return null;
    }
}
