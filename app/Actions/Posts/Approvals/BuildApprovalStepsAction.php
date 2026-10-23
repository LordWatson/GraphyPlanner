<?php

namespace App\Actions\Posts\Approvals;

use App\Models\Post;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step M-6.3 — (re)build a post's approval steps from its client's approver contacts and approval
 * policy. Called whenever a post enters `waiting_client`, so each review round starts from a clean
 * set reflecting the current approvers. A client with no named approver contacts gets a single
 * generic step that the review-link token or any portal reviewer satisfies (legacy behaviour).
 */
class BuildApprovalStepsAction
{
    public function __invoke(Post $post): void
    {
        DB::transaction(function () use ($post) {
            $post->approvalSteps()->delete();

            $contacts = $post->client->approverContacts();

            if ($contacts->isEmpty()) {
                $post->approvalSteps()->create([
                    'org_id' => $post->org_id,
                    'client_contact_id' => null,
                    'position' => 0,
                ]);

                return;
            }

            foreach ($contacts->values() as $index => $contact) {
                $post->approvalSteps()->create([
                    'org_id' => $post->org_id,
                    'client_contact_id' => $contact->id,
                    'position' => $index,
                ]);
            }
        });

        Log::info('Approval steps built', [
            'post_id' => $post->id,
            'org_id' => $post->org_id,
            'policy' => $post->client->approval_policy->value,
            'step_count' => $post->approvalSteps()->count(),
        ]);
    }
}
