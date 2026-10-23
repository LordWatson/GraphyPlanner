<?php

namespace App\Actions\Posts;

use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Models\Post;
use App\Models\ReviewToken;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Step B-3.4 — mints a read-only "Share preview" link for a post (a partner or a sponsor), reusing
 * the `ReviewToken` machinery. Decisions are off by default; `allowComments`/`allowApproval` widen
 * it. The link opens the same `/review/{token}` portal, but with brand-not-client copy and no
 * money fields (as the agency review portal already guarantees).
 *
 * A sponsor's approval through this link is recorded but never changes the post's status — a
 * business post has no `waiting_client` stage (see `ReviewController::decide`). This is the hook
 * paid-partnership approval (B-11.1) will build on.
 */
class CreateSharePreviewAction
{
    public function __construct(private readonly CreateReviewTokenAction $createReviewToken) {}

    /**
     * @return array{token: string, model: ReviewToken}
     */
    public function __invoke(Post $post, bool $allowComments = false, bool $allowApproval = false, ?CarbonInterface $expiresAt = null): array
    {
        $result = ($this->createReviewToken)(
            $post->client,
            $post,
            $expiresAt,
            null,
            $allowComments,
            $allowApproval,
        );

        Log::info('Share preview link created', [
            'post_id' => $post->id,
            'org_id' => $post->org_id,
            'review_token_id' => $result['model']->id,
            'allow_comments' => $result['model']->allow_comments,
            'allow_approval' => $result['model']->allow_approval,
        ]);

        return $result;
    }
}
