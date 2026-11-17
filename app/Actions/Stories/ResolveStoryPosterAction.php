<?php

namespace App\Actions\Stories;

use App\Enums\Role;
use App\Enums\StoryHandoffRecipient;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Scopes\ClientAccessScope;
use App\Models\StoryHandoff;
use App\Models\User;
use App\Services\PostNotificationService;
use Illuminate\Support\Collection;

/**
 * Step S-2.2 (decision D7) — who posts an assisted Story.
 *
 * `team` is one person where there is one: the post's assignee, else whoever created it. With
 * neither, it is everyone on the team (a null user), because a Story nobody was asked to post is
 * a Story that is missed. `client` is the client's own people: the contacts who get review emails
 * (the same list the review request uses), anyone who has consented to WhatsApp, and their portal
 * reviewers.
 *
 * The same class answers "who do we actually tell?" at notification time, so the checklist's
 * warning, the editor's "who posts it" line and the notification can never disagree.
 */
class ResolveStoryPosterAction
{
    /**
     * Who can be asked to post, open a handoff or mark it posted: the roles that work on a post.
     * A Viewer can read the schedule but cannot act on it, so asking one would be asking nobody.
     * The one list `PostPolicy` and the queue read too.
     */
    public const POSTER_ROLES = [Role::Owner, Role::Strategist, Role::Designer];

    /**
     * Users already looked up by this instance, keyed by id (null = not a poster). A list of
     * Stories asks about the same few people over and over.
     *
     * @var array<int, User|null>
     */
    private array $posters = [];

    public function __construct(private readonly PostNotificationService $notifications = new PostNotificationService) {}

    /**
     * @return array{type: StoryHandoffRecipient, user: User|null}
     */
    public function __invoke(PostTarget $target): array
    {
        $target->loadMissing('post.client');
        $post = $target->post;

        if ($post === null || $target->handoffRecipient() === StoryHandoffRecipient::Client) {
            return ['type' => StoryHandoffRecipient::Client, 'user' => null];
        }

        return ['type' => StoryHandoffRecipient::Team, 'user' => $this->teamPoster($post)];
    }

    /**
     * The one team member responsible for the post, or null when nobody is (so everyone is asked).
     * A former member, or somebody who has since become a client reviewer, does not count.
     */
    public function teamPoster(Post $post): ?User
    {
        foreach (array_filter([$post->assignee_id, $post->created_by]) as $userId) {
            $user = $this->poster((int) $userId, $post->org_id);

            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }

    /**
     * What the editor's Story extras panel reads: the client's default for who posts a Story by
     * hand, who on the team can be the poster (so "Sam gets the reminder" follows the assignee
     * picker live), whether the client has anybody to send it to, and the window.
     *
     * @return array{default_recipient: string, can_choose_recipient: bool, client_can_be_notified: bool, posters: list<array{id: int, name: string}>, creator_id: int|null, window_minutes: int}
     */
    public function editorOptions(Post $post): array
    {
        $post->loadMissing('client');
        $client = $post->client;

        return [
            'default_recipient' => ($client->story_handoff_recipient ?? StoryHandoffRecipient::Team)->value,
            // A business workspace has no client to hand a Story to.
            'can_choose_recipient' => $client !== null && ! $client->is_primary,
            'client_can_be_notified' => $client !== null && $this->clientCanBeNotified($client),
            'posters' => array_values(User::query()
                ->where('org_id', $post->org_id)
                ->whereIn('role', self::POSTER_ROLES)
                ->orderBy('name')
                ->get(['id', 'name', 'org_id', 'role'])
                ->filter(fn (User $member) => ClientAccessScope::allowsClientId($member, $post->client_id))
                ->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name])
                ->all()),
            'creator_id' => $post->created_by,
            'window_minutes' => max(1, (int) config('publishing.story_handoff.window_minutes', 120)),
        ];
    }

    private function poster(int $userId, int $orgId): ?User
    {
        if (! array_key_exists($userId, $this->posters)) {
            $this->posters[$userId] = User::query()
                ->whereKey($userId)
                ->whereIn('role', self::POSTER_ROLES)
                ->first();
        }

        $user = $this->posters[$userId];

        return $user !== null && $user->org_id === $orgId ? $user : null;
    }

    /**
     * The team members a handoff is told to: its one poster, or the whole team.
     *
     * @return Collection<int, User>
     */
    public function teamRecipients(StoryHandoff $handoff): Collection
    {
        $post = $handoff->post;

        if ($post === null) {
            return new Collection;
        }

        $poster = $handoff->user_id !== null ? $this->poster($handoff->user_id, $handoff->org_id) : null;

        // "Everyone" is everyone who could actually open it: the roles that work on posts, and,
        // where staff are restricted to their own clients, only the ones on this client.
        return $poster !== null
            ? new Collection([$poster])
            : $this->notifications->orgRecipients($post)
                ->filter(fn (User $user) => in_array($user->role, self::POSTER_ROLES, true)
                    && ClientAccessScope::allowsClientId($user, $post->client_id))
                ->values();
    }

    /**
     * The client's people, by channel: every address to email (review-email contacts and portal
     * reviewers, de-duplicated without regard to case) and every contact who consented to
     * WhatsApp.
     *
     * @return array{emails: list<string>, whatsapp: Collection<int, ClientContact>}
     */
    public function clientRecipients(Client $client): array
    {
        $emails = [];

        foreach ($client->reviewEmailRecipients() as $recipient) {
            $emails[strtolower($recipient['email'])] ??= $recipient['email'];
        }

        $reviewers = User::query()
            ->where('client_id', $client->id)
            ->where('role', Role::ClientReviewer)
            ->pluck('email');

        foreach ($reviewers as $email) {
            $emails[strtolower((string) $email)] ??= (string) $email;
        }

        return [
            'emails' => array_values($emails),
            'whatsapp' => $client->contacts()->receivingWhatsApp()->with('organization')->orderBy('id')->get(),
        ];
    }

    /**
     * Whether a `client` handoff would reach anybody at all (the checklist's soft warning).
     */
    public function clientCanBeNotified(Client $client): bool
    {
        $recipients = $this->clientRecipients($client);

        return $recipients['emails'] !== [] || $recipients['whatsapp']->isNotEmpty();
    }
}
