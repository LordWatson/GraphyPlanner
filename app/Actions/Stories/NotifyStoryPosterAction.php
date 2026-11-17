<?php

namespace App\Actions\Stories;

use App\Actions\Push\SendStoryHandoffPushAction;
use App\Enums\StoryHandoffRecipient;
use App\Enums\WebhookEvent;
use App\Models\StoryHandoff;
use App\Models\User;
use App\Notifications\StoryPublishHandoffReady;
use App\Services\Api\WebhookDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Step S-3.1 — tell the poster an assisted Story is due, on every channel they have.
 *
 * Called by the sweep (`ProcessStoryHandoffsAction`) after it has stamped the handoff, so this
 * never decides *whether* to notify — only who and how:
 *
 * - `team` with a poster → that person; `team` without → everyone on the team;
 * - `client` → the client's review-email contacts and portal reviewers by email, and any contact
 *   who consented to WhatsApp.
 *
 * **Never silent.** A `client` handoff whose client has nobody we can reach falls back to the
 * team, with a warning in the log, rather than telling no one.
 *
 * One link is issued per call and shared by every recipient of it, because issuing a link replaces
 * the last one (`IssueStoryHandoffLinkAction`). The link is never logged.
 */
class NotifyStoryPosterAction
{
    public function __construct(
        private readonly IssueStoryHandoffLinkAction $issueLink = new IssueStoryHandoffLinkAction,
        private readonly ResolveStoryPosterAction $resolvePoster = new ResolveStoryPosterAction,
    ) {}

    public function __invoke(StoryHandoff $handoff, bool $late = false, bool $nudge = false): void
    {
        $handoff->loadMissing(['post.client', 'post.organization', 'target.socialAccount']);
        $post = $handoff->post;

        if ($post === null) {
            return;
        }

        $url = ($this->issueLink)($handoff);
        $notification = new StoryPublishHandoffReady($handoff->id, $url, $late, $nudge);

        $emails = [];
        $contacts = new Collection;

        if ($handoff->recipient_type === StoryHandoffRecipient::Client && $post->client !== null) {
            ['emails' => $emails, 'whatsapp' => $contacts] = $this->resolvePoster->clientRecipients($post->client);
        }

        /** @var Collection<int, User> $users */
        $users = new Collection;

        if ($emails === [] && $contacts->isEmpty()) {
            if ($handoff->recipient_type === StoryHandoffRecipient::Client) {
                Log::warning('Story handoff: the client has nobody to notify, telling the team instead', [
                    'org_id' => $handoff->org_id,
                    'client_id' => $handoff->client_id,
                    'post_id' => $handoff->post_id,
                    'story_handoff_id' => $handoff->id,
                ]);
            }

            $users = $this->resolvePoster->teamRecipients($handoff);
        }

        foreach ($users as $user) {
            $user->notify($notification);
        }

        foreach ($emails as $email) {
            Notification::route('mail', $email)->notify($notification);
        }

        foreach ($contacts as $contact) {
            $contact->notify($notification);
        }

        // Step S-3.2: the phone buzzes. Resolved from the container so the bound transport (or a
        // test fake) is used; a no-op when push is not configured or nobody opted in.
        app(SendStoryHandoffPushAction::class)($handoff, $url, $nudge, $users);

        // Step S-5.5: an agency can route the reminder into its own tools. First notification
        // only, and never the token link — the payload is stored with the delivery.
        if (! $nudge && $post->organization !== null) {
            app(WebhookDispatcher::class)->dispatch($post->organization, WebhookEvent::StoryReadyToPost, [
                'post_id' => $post->id,
                'client_id' => $post->client_id,
                'post_target_id' => $handoff->post_target_id,
                'platform' => $handoff->target?->socialAccount?->platform->value,
                'handle' => $handoff->target?->socialAccount?->handle,
                'due_at' => $handoff->due_at->toIso8601String(),
                'posted_by' => $handoff->recipient_type->value,
                'url' => route('posts.story-handoff.open', ['post' => $post->id, 'target' => $handoff->post_target_id]),
            ]);
        }

        Log::info('Story poster notified', [
            'org_id' => $handoff->org_id,
            'post_id' => $handoff->post_id,
            'post_target_id' => $handoff->post_target_id,
            'story_handoff_id' => $handoff->id,
            'recipient_type' => $handoff->recipient_type->value,
            'user_count' => $users->count(),
            'email_count' => count($emails),
            'whatsapp_count' => $contacts->count(),
            'late' => $late,
            'nudge' => $nudge,
        ]);
    }
}
