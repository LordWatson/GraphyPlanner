<?php

namespace App\Actions\ReviewTokens;

use App\Models\Client;
use App\Models\GridPlan;
use App\Models\Post;
use App\Models\ReviewToken;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateReviewTokenAction
{
    /**
     * Create a review token scoped to a client and optionally one post **or** one grid plan
     * (Step 18.1 — never both, and never another client's). Hashed at rest per spec §10 — the
     * plain token is only ever available via the return value of this action.
     *
     * Step B-3.4: `allowComments`/`allowApproval` widen a share-preview link (default false, so the
     * agency client-review flow is unchanged).
     *
     * @return array{token: string, model: ReviewToken}
     */
    public function __invoke(
        Client $client,
        ?Post $post = null,
        ?CarbonInterface $expiresAt = null,
        ?GridPlan $gridPlan = null,
        bool $allowComments = false,
        bool $allowApproval = false,
    ): array {
        if ($post !== null && $gridPlan !== null) {
            throw new InvalidArgumentException('A review token carries a post or a grid plan, never both.');
        }

        if (($post !== null && $post->client_id !== $client->id) || ($gridPlan !== null && $gridPlan->client_id !== $client->id)) {
            throw new InvalidArgumentException('A review token can only point at its own client\'s work.');
        }

        $plainToken = Str::random(64);

        $token = ReviewToken::create([
            'token_hash' => hash('sha256', $plainToken),
            'client_id' => $client->id,
            'post_id' => $post?->id,
            'grid_plan_id' => $gridPlan?->id,
            'allow_comments' => $allowComments || $allowApproval,
            'allow_approval' => $allowApproval,
            'expires_at' => $expiresAt ?? now()->addDays(7),
        ]);

        Log::info('Review token created', [
            'client_id' => $client->id,
            'post_id' => $post?->id,
            'grid_plan_id' => $gridPlan?->id,
            'review_token_id' => $token->id,
        ]);

        return ['token' => $plainToken, 'model' => $token];
    }
}
