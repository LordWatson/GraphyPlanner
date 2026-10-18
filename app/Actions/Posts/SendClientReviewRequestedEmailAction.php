<?php

namespace App\Actions\Posts;

use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Mail\ClientReviewRequestedMail;
use App\Models\Post;
use App\Models\ReviewToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class SendClientReviewRequestedEmailAction
{
    /**
     * Issue a fresh `ReviewToken` scoped to the post's client and email the client (via Resend)
     * the Step 0.13 review-portal link. Silently skipped (with a warning log) when the client has
     * no `approval_email` on file, so a missing address never blocks the status transition itself.
     *
     * Step I-1.6: every still-valid token for this post is revoked first, so only the newest link
     * works, and the plain link is never persisted — the caller stores the returned token's id.
     * Step I-1.4: the mail is queued after the surrounding transaction commits, so a rolled-back
     * transition never emails the client.
     *
     * @return ReviewToken|null the minted token, or `null` when the email was skipped.
     */
    public function __invoke(
        Post $post,
        CreateReviewTokenAction $createReviewToken = new CreateReviewTokenAction,
    ): ?ReviewToken {
        $client = $post->client;

        if (blank($client?->approval_email)) {
            Log::warning('Skipped client review email — no approval_email on file', [
                'client_id' => $post->client_id,
                'post_id' => $post->id,
            ]);

            return null;
        }

        return DB::transaction(function () use ($post, $client, $createReviewToken) {
            $revoked = ReviewToken::query()
                ->where('post_id', $post->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['revoked_at' => now()]);

            ['token' => $plainToken, 'model' => $token] = $createReviewToken($client, $post);

            $reviewUrl = URL::to("/review/{$plainToken}");
            $email = (string) $client->approval_email;

            DB::afterCommit(function () use ($email, $post, $reviewUrl, $client, $token) {
                Mail::to($email)->send(new ClientReviewRequestedMail($post, $reviewUrl));

                Log::info('Client review requested email queued', [
                    'client_id' => $client->id,
                    'post_id' => $post->id,
                    'review_token_id' => $token->id,
                ]);
            });

            if ($revoked > 0) {
                Log::info('Revoked previous review tokens for post', [
                    'post_id' => $post->id,
                    'revoked_count' => $revoked,
                ]);
            }

            return $token;
        });
    }
}
