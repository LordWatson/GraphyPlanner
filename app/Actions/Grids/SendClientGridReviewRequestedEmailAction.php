<?php

namespace App\Actions\Grids;

use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Mail\ClientGridReviewRequestedMail;
use App\Models\GridPlan;
use App\Models\ReviewToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class SendClientGridReviewRequestedEmailAction
{
    /**
     * Step 18.8 — mint a grid-scoped `ReviewToken` (revoking this grid's older live links, so only
     * the newest works) and email the client "Your new grid is ready to look at." Skipped with a
     * warning when the client has no `approval_email`, so a missing address never blocks the
     * transition. The mail is queued after the surrounding transaction commits; the plain link
     * is never stored.
     */
    public function __invoke(GridPlan $plan, CreateReviewTokenAction $createReviewToken = new CreateReviewTokenAction): ?ReviewToken
    {
        $plan->loadMissing('client');
        $client = $plan->client;

        if (blank($client->approval_email)) {
            Log::warning('Skipped client grid review email — no approval_email on file', [
                'client_id' => $plan->client_id,
                'grid_plan_id' => $plan->id,
            ]);

            return null;
        }

        return DB::transaction(function () use ($plan, $client, $createReviewToken) {
            $revoked = ReviewToken::query()
                ->where('grid_plan_id', $plan->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['revoked_at' => now()]);

            ['token' => $plainToken, 'model' => $token] = $createReviewToken($client, gridPlan: $plan);

            $reviewUrl = URL::to("/review/{$plainToken}");
            $email = (string) $client->approval_email;

            DB::afterCommit(function () use ($email, $plan, $reviewUrl, $token) {
                Mail::to($email)->send(new ClientGridReviewRequestedMail($plan, $reviewUrl));

                Log::info('Client grid review email queued', [
                    'client_id' => $plan->client_id,
                    'grid_plan_id' => $plan->id,
                    'review_token_id' => $token->id,
                ]);
            });

            if ($revoked > 0) {
                Log::info('Revoked previous review tokens for grid plan', ['grid_plan_id' => $plan->id, 'revoked_count' => $revoked]);
            }

            return $token;
        });
    }
}
