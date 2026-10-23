<?php

namespace App\Actions\Posts;

use App\Actions\Portal\CreatePortalLoginTokenAction;
use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Enums\Role;
use App\Mail\ClientReviewRequestedMail;
use App\Models\Post;
use App\Models\ReviewToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class SendClientReviewRequestedEmailAction
{
    /**
     * Email every review-email contact of the post's client (Step M-6.1) their review link, and
     * record a fresh `ReviewToken` for the post. Each recipient who already has a portal login
     * (`Role::ClientReviewer`) gets a passwordless **magic link** (Step M-6.2) that signs them into
     * the portal on this post; everyone else gets the Step 0.13 token-only `/review/:token` link.
     * Silently skipped (with a warning log) when the client has no review-email contact, so a missing
     * address never blocks the status transition.
     *
     * Step I-1.6: every still-valid review token for this post is revoked first, so only the newest
     * token-only link works, and no plain link is ever persisted. Step I-1.4: the mail is queued
     * after the surrounding transaction commits, so a rolled-back transition never emails the client.
     *
     * @return ReviewToken|null the minted review token, or `null` when the email was skipped.
     */
    public function __invoke(
        Post $post,
        CreateReviewTokenAction $createReviewToken = new CreateReviewTokenAction,
        CreatePortalLoginTokenAction $createPortalLoginToken = new CreatePortalLoginTokenAction,
    ): ?ReviewToken {
        $client = $post->client;

        $recipients = $client?->reviewEmailRecipients() ?? [];

        if ($recipients === []) {
            Log::warning('Skipped client review email — no review-email contacts on file', [
                'client_id' => $post->client_id,
                'post_id' => $post->id,
            ]);

            return null;
        }

        return DB::transaction(function () use ($post, $client, $createReviewToken, $createPortalLoginToken, $recipients) {
            $revoked = ReviewToken::query()
                ->where('post_id', $post->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['revoked_at' => now()]);

            ['token' => $plainReviewToken, 'model' => $token] = $createReviewToken($client, $post);
            $reviewUrl = URL::to("/review/{$plainReviewToken}");

            // Which recipient emails already have a portal login for this client (case-insensitive).
            $portalUsers = User::query()
                ->where('client_id', $client->id)
                ->where('role', Role::ClientReviewer)
                ->whereIn('email', array_map(fn (array $recipient) => $recipient['email'], $recipients))
                ->get()
                ->keyBy(fn (User $user) => strtolower($user->email));

            /** @var list<array{email: string, url: string}> $deliveries */
            $deliveries = [];

            foreach ($recipients as $recipient) {
                $portalUser = $portalUsers->get(strtolower($recipient['email']));

                if ($portalUser !== null) {
                    ['token' => $plainLoginToken] = $createPortalLoginToken($portalUser, $post);
                    $url = URL::to("/portal-login/{$plainLoginToken}");
                } else {
                    $url = $reviewUrl;
                }

                $deliveries[] = ['email' => $recipient['email'], 'url' => $url];
            }

            DB::afterCommit(function () use ($deliveries, $post, $client, $token) {
                foreach ($deliveries as $delivery) {
                    Mail::to($delivery['email'])->send(new ClientReviewRequestedMail($post, $delivery['url']));
                }

                Log::info('Client review requested email queued', [
                    'client_id' => $client->id,
                    'post_id' => $post->id,
                    'review_token_id' => $token->id,
                    'recipient_count' => count($deliveries),
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
