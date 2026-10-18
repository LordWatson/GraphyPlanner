<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\PostActivityLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ResendClientReviewEmailAction
{
    /**
     * Re-send the Step 0.13 review-portal link email for a post still waiting on the client — an
     * org user triggers this when the client claims they never received (or lost) the original.
     *
     * Step I-1.6: the original link is never stored, so this mints a fresh token (revoking the
     * previous ones) through `SendClientReviewRequestedEmailAction` instead of replaying a URL.
     */
    public function __invoke(
        PostActivityLog $activityLog,
        SendClientReviewRequestedEmailAction $sendClientReviewRequestedEmail = new SendClientReviewRequestedEmailAction,
    ): void {
        $post = $activityLog->post;

        if ($activityLog->to_status !== PostStatus::WaitingClient || $post->status !== PostStatus::WaitingClient) {
            throw ValidationException::withMessages([
                'review' => __('Only a post that is still waiting on the client can have its review link re-sent.'),
            ]);
        }

        $token = $sendClientReviewRequestedEmail($post);

        Log::info('Client review requested email re-sent', [
            'client_id' => $post->client_id,
            'post_id' => $post->id,
            'activity_log_id' => $activityLog->id,
            'review_token_id' => $token?->id,
        ]);
    }
}
