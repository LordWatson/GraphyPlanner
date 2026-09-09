<?php

namespace App\Actions\ReviewTokens;

use App\Models\Client;
use App\Models\Post;
use App\Models\ReviewToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreateReviewTokenAction
{
    /**
     * Create a review token scoped to a client (and optionally one post). Hashed at rest per
     * spec §10 — the plain token is only ever available via the return value of this action.
     *
     * @return array{token: string, model: ReviewToken}
     */
    public function __invoke(Client $client, ?Post $post = null, ?Carbon $expiresAt = null): array
    {
        $plainToken = Str::random(64);

        $token = ReviewToken::create([
            'token_hash' => hash('sha256', $plainToken),
            'client_id' => $client->id,
            'post_id' => $post?->id,
            'expires_at' => $expiresAt ?? now()->addDays(7),
        ]);

        Log::info('Review token created', [
            'client_id' => $client->id,
            'post_id' => $post?->id,
            'review_token_id' => $token->id,
        ]);

        return ['token' => $plainToken, 'model' => $token];
    }
}
