<?php

namespace App\Actions\Posts;

use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\ReviewToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * MOVE (ux-overhaul, post-editor journey) — mint a fresh, shareable client review link the studio
 * can copy and send over any channel (WhatsApp, Slack, SMS), not just the built-in email. Unlike
 * `SendClientReviewRequestedEmailAction` it needs no review-email contact on file, so it is the one
 * way to get a review link to a client who never gave an email address.
 *
 * Mirrors the email send's security model (Step I-1.6): every still-valid review token for the post
 * is revoked first, so only the newest link ever works and no plain link is persisted — the
 * plaintext URL is returned here and nowhere else.
 */
class CreateShareableReviewLinkAction
{
    public function __invoke(
        Post $post,
        CreateReviewTokenAction $createReviewToken = new CreateReviewTokenAction,
    ): string {
        if ($post->status !== PostStatus::WaitingClient) {
            throw ValidationException::withMessages([
                'review' => __('Only a post that is waiting on the client has a review link to share.'),
            ]);
        }

        $client = $post->client;

        if ($client === null) {
            throw ValidationException::withMessages([
                'review' => __('This post has no client to review it.'),
            ]);
        }

        return DB::transaction(function () use ($post, $client, $createReviewToken) {
            $revoked = ReviewToken::query()
                ->where('post_id', $post->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['revoked_at' => now()]);

            ['token' => $plainToken, 'model' => $token] = $createReviewToken($client, $post);

            Log::info('Shareable review link minted', [
                'client_id' => $client->id,
                'post_id' => $post->id,
                'review_token_id' => $token->id,
                'revoked_count' => $revoked,
            ]);

            return URL::to("/review/{$plainToken}");
        });
    }
}
