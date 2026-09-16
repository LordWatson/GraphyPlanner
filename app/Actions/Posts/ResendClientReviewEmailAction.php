<?php

namespace App\Actions\Posts;

use App\Mail\ClientReviewRequestedMail;
use App\Models\PostActivityLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ResendClientReviewEmailAction
{
    /**
     * Re-send the Step 0.13 review-portal link email for an existing `PostActivityLog` entry
     * (the `waiting_client` transition that first sent it), reusing the same signed URL rather
     * than issuing a new `ReviewToken` — an org user triggers this when the client claims they
     * never received (or lost) the original email.
     */
    public function __invoke(PostActivityLog $activityLog): void
    {
        if (blank($activityLog->review_url)) {
            throw new \InvalidArgumentException(
                "Activity log {$activityLog->id} has no review URL to resend."
            );
        }

        $post = $activityLog->post;
        $client = $post->client;

        if (blank($client?->approval_email)) {
            Log::warning('Skipped resending client review email — no approval_email on file', [
                'client_id' => $post->client_id,
                'post_id' => $post->id,
            ]);

            return;
        }

        Mail::to($client->approval_email)->send(new ClientReviewRequestedMail($post, $activityLog->review_url));

        Log::info('Client review requested email re-sent', [
            'client_id' => $client->id,
            'post_id' => $post->id,
            'activity_log_id' => $activityLog->id,
        ]);
    }
}
