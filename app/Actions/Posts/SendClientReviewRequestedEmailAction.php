<?php

namespace App\Actions\Posts;

use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Mail\ClientReviewRequestedMail;
use App\Models\Post;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class SendClientReviewRequestedEmailAction
{
    /**
     * Issue a fresh `ReviewToken` scoped to the post's client and email the client (via Resend)
     * the Step 0.13 review-portal link. Silently skipped (with a warning log) when the client has
     * no `approval_email` on file, so a missing address never blocks the status transition itself.
     */
    public function __invoke(
        Post $post,
        CreateReviewTokenAction $createReviewToken = new CreateReviewTokenAction,
    ): void {
        $client = $post->client;

        if (blank($client?->approval_email)) {
            Log::warning('Skipped client review email — no approval_email on file', [
                'client_id' => $post->client_id,
                'post_id' => $post->id,
            ]);

            return;
        }

        ['token' => $plainToken] = $createReviewToken($client, $post);

        $reviewUrl = URL::to("/review/{$plainToken}");

        Mail::to($client->approval_email)->send(new ClientReviewRequestedMail($post, $reviewUrl));

        Log::info('Client review requested email sent', [
            'client_id' => $client->id,
            'post_id' => $post->id,
        ]);
    }
}
