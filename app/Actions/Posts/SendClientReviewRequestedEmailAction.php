<?php

namespace App\Actions\Posts;

use App\Actions\Portal\CreatePortalLoginTokenAction;
use App\Actions\ReviewTokens\CreateReviewTokenAction;
use App\Enums\Role;
use App\Mail\ClientReviewRequestedMail;
use App\Models\Post;
use App\Models\ReviewToken;
use App\Models\User;
use App\Notifications\PostWaitingForClientReview;
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

        // Step W-2.1: WhatsApp is a channel of its own, not a copy of the email. A client whose
        // approver has consented to WhatsApp but is not a review-email contact still gets asked.
        $whatsAppContacts = $client === null
            ? collect()
            : $client->contacts()->receivingWhatsApp()->orderBy('id')->get();

        if ($recipients === [] && $whatsAppContacts->isEmpty()) {
            Log::warning('Skipped client review request — no review-email or WhatsApp contacts on file', [
                'client_id' => $post->client_id,
                'post_id' => $post->id,
            ]);

            return null;
        }

        return DB::transaction(function () use ($post, $client, $createReviewToken, $createPortalLoginToken, $recipients, $whatsAppContacts) {
            $revoked = ReviewToken::query()
                ->where('post_id', $post->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['revoked_at' => now()]);

            ['token' => $plainReviewToken, 'model' => $token] = $createReviewToken($client, $post);
            $reviewUrl = URL::to("/review/{$plainReviewToken}");

            // Which addresses on either channel already have a portal login for this client
            // (case-insensitive).
            $addresses = array_values(array_unique(array_merge(
                array_map(fn (array $recipient) => $recipient['email'], $recipients),
                $whatsAppContacts->pluck('email')->all(),
            )));

            $portalUsers = User::query()
                ->where('client_id', $client->id)
                ->where('role', Role::ClientReviewer)
                ->whereIn('email', $addresses)
                ->get()
                ->keyBy(fn (User $user) => strtolower($user->email));

            // One resolver for both channels: a magic portal link for somebody who has a login,
            // the token-only review link for everybody else. Memoised, so a contact who is on the
            // email list *and* on WhatsApp gets one link rather than two.
            $links = [];

            $urlFor = function (string $email) use (&$links, $portalUsers, $createPortalLoginToken, $post, $reviewUrl): string {
                $key = strtolower($email);

                if (! array_key_exists($key, $links)) {
                    $portalUser = $portalUsers->get($key);

                    $links[$key] = $portalUser === null
                        ? $reviewUrl
                        : URL::to('/portal-login/'.$createPortalLoginToken($portalUser, $post)['token']);
                }

                return $links[$key];
            };

            /** @var list<array{email: string, url: string}> $deliveries */
            $deliveries = [];

            foreach ($recipients as $recipient) {
                $deliveries[] = ['email' => $recipient['email'], 'url' => $urlFor($recipient['email'])];
            }

            DB::afterCommit(function () use ($deliveries, $post, $client, $token, $whatsAppContacts, $urlFor) {
                foreach ($deliveries as $delivery) {
                    Mail::to($delivery['email'])->send(new ClientReviewRequestedMail($post, $delivery['url']));
                }

                // Step W-2.1 — the headline message: one tap from WhatsApp to the review screen.
                foreach ($whatsAppContacts as $contact) {
                    $contact->notify(new PostWaitingForClientReview($post, $urlFor($contact->email)));
                }

                Log::info('Client review requested', [
                    'client_id' => $client->id,
                    'post_id' => $post->id,
                    'review_token_id' => $token->id,
                    'recipient_count' => count($deliveries),
                    'whatsapp_recipient_count' => $whatsAppContacts->count(),
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
