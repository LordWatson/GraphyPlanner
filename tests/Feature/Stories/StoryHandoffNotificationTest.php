<?php

namespace Tests\Feature\Stories;

use App\Actions\Push\SendStoryHandoffPushAction;
use App\Actions\Stories\CompleteStoryHandoffAction;
use App\Actions\Stories\IssueStoryHandoffLinkAction;
use App\Actions\Stories\NotifyStoryPosterAction;
use App\Actions\Stories\ResolveStoryPosterAction;
use App\Actions\Stories\ShowStoryHandoffAction;
use App\Contracts\PushNotifier;
use App\Enums\NotificationCategory;
use App\Enums\Role;
use App\Enums\StoryHandoffOutcome;
use App\Enums\StoryHandoffRecipient;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhookJob;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PushSubscription;
use App\Models\StoryHandoff;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Notifications\StoryPublishHandoffReady;
use App\Support\WhatsApp\MessageTemplate;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsStoryHandoffs;
use Tests\Support\FakePushNotifier;
use Tests\TestCase;

/**
 * Steps S-3.1 and S-3.2 (and S-5.5) — who is told an assisted Story is due, on which channels, and
 * that the handoff token only ever travels in the email, the WhatsApp message and the push: never
 * in a stored notification row, a log line or a webhook payload.
 */
class StoryHandoffNotificationTest extends TestCase
{
    use BuildsStoryHandoffs, RefreshDatabase;

    private FakePushNotifier $push;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-20 10:00:00');
        $this->setUpStoryHandoffs();

        $this->push = new FakePushNotifier;
        $this->app->instance(PushNotifier::class, $this->push);
    }

    /**
     * An open handoff for a Story due now on `@acmecoffee`.
     *
     * @param  array<string, mixed>  $postAttributes
     */
    private function handoff(array $postAttributes = [], string $timezone = 'UTC'): StoryHandoff
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => 'scheduled',
            'publish_dispatch_id' => 'dispatch-1',
            ...$postAttributes,
        ]);
        $this->addStoryChannel($post, account: $this->instagramAccount('@acmecoffee', $timezone));
        $this->refusingAdapter();

        return $this->openHandoff($this->startPublishing($post));
    }

    private function tokenFrom(string $url): string
    {
        return (string) last(explode('/', (string) parse_url($url, PHP_URL_PATH)));
    }

    private function optIntoWhatsApp(User $user): User
    {
        $user->update(['notification_preferences' => [NotificationCategory::Publishing->value => ['whatsapp' => true]]]);

        return $user;
    }

    private function consentingContact(string $phone = '+447700900124'): ClientContact
    {
        $contact = ClientContact::factory()->forClient($this->client)->create([
            'phone' => $phone,
            'receives_review_emails' => false,
        ]);
        $contact->setWhatsAppConsent(true, 'staff');
        $contact->save();

        return $contact;
    }

    // --- Recipients ---------------------------------------------------------------------------

    public function test_a_team_handoff_with_a_poster_notifies_only_that_person(): void
    {
        Notification::fake();
        $owner = $this->member(Role::Owner);
        $assignee = $this->member(Role::Designer);
        $handoff = $this->handoff(['assignee_id' => $assignee->id]);
        $this->assertSame($assignee->id, $handoff->user_id);

        app(NotifyStoryPosterAction::class)($handoff);

        Notification::assertSentToTimes($assignee, StoryPublishHandoffReady::class, 1);
        Notification::assertNotSentTo($owner, StoryPublishHandoffReady::class);
        Notification::assertCount(1);
    }

    public function test_a_team_handoff_without_a_poster_notifies_every_member_who_can_act_on_it(): void
    {
        Notification::fake();
        $owner = $this->member(Role::Owner);
        $strategist = $this->member(Role::Strategist);
        $designer = $this->member(Role::Designer);
        $viewer = $this->member(Role::Viewer);
        $reviewer = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $this->client->id]);
        $outsider = $this->member(Role::Owner, Organization::factory()->create());

        $handoff = $this->handoff();
        $this->assertSame(StoryHandoffRecipient::Team, $handoff->recipient_type);
        $this->assertNull($handoff->user_id);

        app(NotifyStoryPosterAction::class)($handoff);

        Notification::assertSentTo([$owner, $strategist, $designer], StoryPublishHandoffReady::class);
        Notification::assertNotSentTo($viewer, StoryPublishHandoffReady::class);
        Notification::assertNotSentTo($reviewer, StoryPublishHandoffReady::class);
        Notification::assertNotSentTo($outsider, StoryPublishHandoffReady::class);
        Notification::assertCount(3);
    }

    public function test_a_client_handoff_emails_the_clients_people_and_whatsapps_a_consenting_contact(): void
    {
        Notification::fake();
        $owner = $this->member(Role::Owner);
        $this->client->update(['story_handoff_recipient' => StoryHandoffRecipient::Client]);

        $first = ClientContact::factory()->forClient($this->client)->create(['email' => 'jess@acme.test', 'receives_review_emails' => true]);
        $second = ClientContact::factory()->forClient($this->client)->create(['email' => 'sam@acme.test', 'receives_review_emails' => true]);
        ClientContact::factory()->forClient($this->client)->billing()->create(['email' => 'accounts@acme.test']);
        $reviewer = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)
            ->create(['client_id' => $this->client->id, 'email' => 'portal@acme.test']);
        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        ClientContact::factory()->forClient($otherClient)->create(['email' => 'someone@else.test', 'receives_review_emails' => true]);
        $whatsApp = $this->consentingContact();

        $handoff = $this->handoff(['assignee_id' => $owner->id]);
        $this->assertSame(StoryHandoffRecipient::Client, $handoff->recipient_type);

        app(NotifyStoryPosterAction::class)($handoff);

        $emailed = [];
        Notification::assertSentOnDemand(StoryPublishHandoffReady::class, function (StoryPublishHandoffReady $notification, array $channels, AnonymousNotifiable $notifiable) use (&$emailed) {
            $emailed[] = $notifiable->routes['mail'];

            return true;
        });
        sort($emailed);
        $this->assertSame(['jess@acme.test', 'portal@acme.test', 'sam@acme.test'], $emailed);

        Notification::assertSentToTimes($whatsApp, StoryPublishHandoffReady::class, 1);
        Notification::assertNotSentTo([$first, $second], StoryPublishHandoffReady::class);
        // The portal reviewer is emailed by address; the team is not told, and gets no push.
        Notification::assertNotSentTo($reviewer, StoryPublishHandoffReady::class);
        Notification::assertNotSentTo($owner, StoryPublishHandoffReady::class);
        PushSubscription::factory()->create(['user_id' => $owner->id]);
        $this->assertSame([], $this->push->sent);
    }

    public function test_a_client_handoff_with_nobody_to_reach_falls_back_to_the_team(): void
    {
        Notification::fake();
        $owner = $this->member(Role::Owner);
        $strategist = $this->member(Role::Strategist);
        $viewer = $this->member(Role::Viewer);
        $this->client->update(['story_handoff_recipient' => StoryHandoffRecipient::Client, 'approval_email' => null]);

        $handoff = $this->handoff();
        $this->assertSame(StoryHandoffRecipient::Client, $handoff->recipient_type);

        app(NotifyStoryPosterAction::class)($handoff);

        Notification::assertSentTo([$owner, $strategist], StoryPublishHandoffReady::class);
        Notification::assertNotSentTo($viewer, StoryPublishHandoffReady::class);
        Notification::assertNothingSentTo(new AnonymousNotifiable);
        Notification::assertCount(2);
    }

    public function test_the_everyone_fallback_leaves_out_members_restricted_to_other_clients(): void
    {
        Notification::fake();
        $this->org->update(['restrict_staff_to_assigned_clients' => true]);
        $otherClient = Client::factory()->for($this->org, 'organization')->create();

        $owner = $this->member(Role::Owner);
        $onThisClient = $this->member(Role::Designer);
        $onThisClient->assignedClients()->attach($this->client);
        $onAnotherClient = $this->member(Role::Designer);
        $onAnotherClient->assignedClients()->attach($otherClient);
        $onNoClient = $this->member(Role::Strategist);

        $handoff = $this->handoff();
        $this->assertNull($handoff->user_id);

        app(NotifyStoryPosterAction::class)($handoff);

        // Everyone who could actually open it: the Owner sees every client, the designer is on this one.
        Notification::assertSentTo([$owner, $onThisClient], StoryPublishHandoffReady::class);
        Notification::assertNotSentTo($onAnotherClient, StoryPublishHandoffReady::class);
        Notification::assertNotSentTo($onNoClient, StoryPublishHandoffReady::class);
        Notification::assertCount(2);

        $recipients = app(ResolveStoryPosterAction::class)->teamRecipients($handoff->load('post'));
        $this->assertEqualsCanonicalizing([$owner->id, $onThisClient->id], $recipients->pluck('id')->all());
    }

    public function test_without_client_restrictions_the_everyone_fallback_is_unchanged(): void
    {
        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        $owner = $this->member(Role::Owner);
        $designer = $this->member(Role::Designer);
        // Assigned elsewhere, but the organization does not restrict staff: still on the team.
        $designer->assignedClients()->attach($otherClient);
        $handoff = $this->handoff();

        $recipients = app(ResolveStoryPosterAction::class)->teamRecipients($handoff->load('post'));

        $this->assertEqualsCanonicalizing([$owner->id, $designer->id], $recipients->pluck('id')->all());
    }

    public function test_the_roles_that_can_be_asked_to_post_are_owner_strategist_and_designer(): void
    {
        $this->assertSame([Role::Owner, Role::Strategist, Role::Designer], ResolveStoryPosterAction::POSTER_ROLES);
    }

    public function test_the_editor_options_list_only_the_posters_who_can_reach_this_client(): void
    {
        $this->org->update(['restrict_staff_to_assigned_clients' => true]);
        $otherClient = Client::factory()->for($this->org, 'organization')->create();

        $owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create(['name' => 'Zed Owner']);
        $onThisClient = User::factory()->for($this->org, 'organization')->role(Role::Designer)->create(['name' => 'Amy Designer']);
        $onThisClient->assignedClients()->attach($this->client);
        $onAnotherClient = User::factory()->for($this->org, 'organization')->role(Role::Designer)->create(['name' => 'Bob Elsewhere']);
        $onAnotherClient->assignedClients()->attach($otherClient);
        User::factory()->for($this->org, 'organization')->role(Role::Viewer)->create(['name' => 'Vic Viewer']);
        User::factory()->for(Organization::factory()->create(), 'organization')->role(Role::Owner)->create(['name' => 'Other Agency']);

        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'created_by' => $onThisClient->id]);

        $options = app(ResolveStoryPosterAction::class)->editorOptions($post);

        $this->assertSame(
            ['default_recipient', 'can_choose_recipient', 'client_can_be_notified', 'posters', 'creator_id', 'window_minutes'],
            array_keys($options),
        );
        // By name, and never somebody who could not open the post.
        $this->assertSame([
            ['id' => $onThisClient->id, 'name' => 'Amy Designer'],
            ['id' => $owner->id, 'name' => 'Zed Owner'],
        ], $options['posters']);
        $this->assertSame('team', $options['default_recipient']);
        $this->assertTrue($options['can_choose_recipient']);
        $this->assertSame($onThisClient->id, $options['creator_id']);
        $this->assertSame(120, $options['window_minutes']);

        // And it is what the editor is given.
        $this->withoutVite();
        $this->actingAs($owner)->get(route('posts.edit', $post))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('storyHandoff', $options));
    }

    // --- Not sent once the handoff has closed -------------------------------------------------

    public function test_should_send_is_true_only_while_the_handoff_is_open_and_current(): void
    {
        $assignee = $this->member(Role::Designer);
        $handoff = $this->handoff(['assignee_id' => $assignee->id]);
        $notification = new StoryPublishHandoffReady($handoff->id, 'https://alima.test/story/abc');

        foreach (['database', 'mail', 'whatsapp'] as $channel) {
            $this->assertTrue($notification->shouldSend($assignee, $channel), $channel);
        }

        // Its channel moved to a new attempt (a retry or a recall the sweep has not closed yet).
        $target = $handoff->target()->firstOrFail();
        $attempt = $target->publish_attempt_id;
        $target->update(['publish_attempt_id' => 'a-newer-attempt']);
        $this->assertFalse($notification->shouldSend($assignee, 'mail'));
        $target->update(['publish_attempt_id' => $attempt]);
        $this->assertTrue($notification->shouldSend($assignee, 'mail'));

        $handoff->update(['outcome' => StoryHandoffOutcome::Cancelled, 'completed_at' => now()]);
        foreach (['database', 'mail', 'whatsapp'] as $channel) {
            $this->assertFalse($notification->shouldSend($assignee, $channel), $channel);
        }

        $this->assertFalse((new StoryPublishHandoffReady(999_999, 'https://alima.test/story/abc'))->shouldSend($assignee, 'mail'));
    }

    public function test_should_send_is_false_once_the_story_was_confirmed_as_posted(): void
    {
        $assignee = $this->member(Role::Designer);
        $handoff = $this->handoff(['assignee_id' => $assignee->id]);
        $notification = new StoryPublishHandoffReady($handoff->id, 'https://alima.test/story/abc', nudge: true);

        app(CompleteStoryHandoffAction::class)($handoff, $assignee);

        $this->assertFalse($notification->shouldSend($assignee, 'mail'));
        $this->assertFalse($notification->shouldSend(Notification::route('mail', 'jess@acme.test'), 'mail'));
    }

    public function test_a_reminder_queued_before_the_story_was_confirmed_sends_nothing_afterwards(): void
    {
        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        $assignee = $this->member(Role::Designer);
        $handoff = $this->handoff(['assignee_id' => $assignee->id]);
        $url = app(IssueStoryHandoffLinkAction::class)($handoff);

        // While it is open, the bell row and the email both go (the queue runs inline in tests).
        $assignee->notify(new StoryPublishHandoffReady($handoff->id, $url));
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $assignee->id)->count());
        $this->assertCount(1, $transport->messages());

        // The nudge is built, then somebody confirms the Story before the worker sends it.
        $nudge = new StoryPublishHandoffReady($handoff->id, $url, nudge: true);
        app(CompleteStoryHandoffAction::class)($handoff, $assignee);

        $assignee->notify($nudge);
        Notification::route('mail', 'jess@acme.test')->notify($nudge);

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $assignee->id)->count());
        $this->assertCount(1, $transport->messages());
    }

    // --- Channels -----------------------------------------------------------------------------

    public function test_a_member_gets_the_bell_and_the_email(): void
    {
        $notification = new StoryPublishHandoffReady(1, 'https://alima.test/story/abc');

        $this->assertSame(['database', 'mail'], $notification->via($this->member()));
    }

    public function test_whatsapp_is_added_only_for_a_member_who_opted_in_and_verified_a_number(): void
    {
        $notification = new StoryPublishHandoffReady(1, 'https://alima.test/story/abc');

        $both = $this->optIntoWhatsApp(User::factory()->for($this->org, 'organization')->whatsAppVerified()->create());
        $this->assertSame(['database', 'mail', 'whatsapp'], $notification->via($both));

        $numberOnly = User::factory()->for($this->org, 'organization')->whatsAppVerified()->create();
        $this->assertSame(['database', 'mail'], $notification->via($numberOnly));

        $optInOnly = $this->optIntoWhatsApp($this->member());
        $this->assertSame(['database', 'mail'], $notification->via($optInOnly));

        // Opted into another category: this one is `publishing`.
        $wrongCategory = User::factory()->for($this->org, 'organization')->whatsAppVerified()->create([
            'notification_preferences' => [NotificationCategory::Approvals->value => ['whatsapp' => true]],
        ]);
        $this->assertSame(['database', 'mail'], $notification->via($wrongCategory));
    }

    public function test_a_member_who_muted_publishing_in_app_still_gets_the_email(): void
    {
        $muted = $this->member();
        $muted->update(['notification_preferences' => [NotificationCategory::Publishing->value => ['in_app' => false]]]);

        $this->assertSame(['mail'], (new StoryPublishHandoffReady(1, 'https://alima.test/story/abc'))->via($muted));
    }

    public function test_an_address_gets_the_email_and_a_contact_gets_whatsapp_only_with_consent(): void
    {
        $notification = new StoryPublishHandoffReady(1, 'https://alima.test/story/abc');

        $this->assertSame(['mail'], $notification->via(Notification::route('mail', 'jess@acme.test')));
        $this->assertSame(['whatsapp'], $notification->via($this->consentingContact()));
        $this->assertSame([], $notification->via(ClientContact::factory()->forClient($this->client)->create()));
    }

    public function test_it_is_a_queued_encrypted_publishing_notification(): void
    {
        $notification = new StoryPublishHandoffReady(1, 'https://alima.test/story/abc');

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $notification);
        $this->assertSame(NotificationCategory::Publishing, NotificationCategory::forNotificationClass(StoryPublishHandoffReady::class));
    }

    // --- Content ------------------------------------------------------------------------------

    public function test_the_stored_bell_payload_points_at_the_signed_in_route_and_holds_no_token(): void
    {
        $assignee = $this->member(Role::Designer);
        $handoff = $this->handoff(['assignee_id' => $assignee->id]);
        $url = app(IssueStoryHandoffLinkAction::class)($handoff);
        $token = $this->tokenFrom($url);
        $this->assertSame(64, strlen($token));

        $notification = new StoryPublishHandoffReady($handoff->id, $url);
        $openRoute = route('posts.story-handoff.open', ['post' => $handoff->post_id, 'target' => $handoff->post_target_id]);

        $payload = $notification->toArray($assignee);
        $this->assertSame($openRoute, $payload['url']);
        $this->assertSame($handoff->post_id, $payload['post_id']);
        $this->assertSame('Time to post.', $payload['title']);
        $this->assertSame('@acmecoffee’s story is ready.', $payload['body']);

        // And what is really written to the `notifications` table (the bell channel on its own;
        // the email has its own test).
        Notification::sendNow($assignee, $notification, ['database']);

        $row = DB::table('notifications')->where('notifiable_id', $assignee->id)->sole();
        $this->assertSame(StoryPublishHandoffReady::class, $row->type);
        $this->assertStringNotContainsString($token, (string) $row->data);
        $this->assertStringNotContainsString('/story/', str_replace('\\/', '/', (string) $row->data));
        $this->assertSame($openRoute, json_decode((string) $row->data, true)['url']);
    }

    public function test_a_late_notification_says_when_the_story_was_due_in_the_accounts_timezone(): void
    {
        $assignee = $this->member(Role::Designer);
        $handoff = $this->handoff(['assignee_id' => $assignee->id], 'America/New_York');

        $payload = (new StoryPublishHandoffReady($handoff->id, 'https://alima.test/story/abc', late: true))->toArray($assignee);

        $this->assertSame('Time to post.', $payload['title']);
        // 10:00 UTC on 20 November is 05:00 in New York.
        $this->assertSame('@acmecoffee’s story was due at 05:00.', $payload['body']);
    }

    public function test_a_nudge_says_the_story_is_still_waiting(): void
    {
        $assignee = $this->member(Role::Designer);
        $handoff = $this->handoff(['assignee_id' => $assignee->id]);

        $payload = (new StoryPublishHandoffReady($handoff->id, 'https://alima.test/story/abc', late: true, nudge: true))->toArray($assignee);

        $this->assertSame('Still to post.', $payload['title']);
        $this->assertSame('@acmecoffee’s story is still waiting to be posted.', $payload['body']);
    }

    public function test_the_email_renders_with_the_account_the_extras_and_the_token_link(): void
    {
        $assignee = $this->member(Role::Designer);
        $handoff = $this->handoff(['assignee_id' => $assignee->id]);
        $url = app(IssueStoryHandoffLinkAction::class)($handoff);

        $mail = (new StoryPublishHandoffReady($handoff->id, $url))->toMail($assignee);
        $html = (string) $mail->render();

        $this->assertSame('Time to post: the story for @acmecoffee', $mail->subject);
        $this->assertStringContainsString('@acmecoffee', $html);
        $this->assertStringContainsString('Acme Coffee', $html);
        $this->assertStringContainsString('https://example.com/offer', $html);
        $this->assertStringContainsString('Shop now', $html);
        $this->assertStringContainsString('href="'.$url.'"', $html);
        $this->assertStringContainsString('Open the story', $html);
        $this->assertStringContainsString('Fri 20 Nov, 10:00 (UTC)', $html);
    }

    public function test_whatsapp_uses_the_generic_template_until_the_dedicated_one_is_approved(): void
    {
        config(['services.whatsapp.templates.story_ready_to_post' => null]);
        $assignee = $this->member(Role::Designer);
        $handoff = $this->handoff(['assignee_id' => $assignee->id]);
        $url = app(IssueStoryHandoffLinkAction::class)($handoff);

        $message = (new StoryPublishHandoffReady($handoff->id, $url))->toWhatsApp($assignee);

        $this->assertSame(MessageTemplate::Notification, $message->template);
        $this->assertSame(['title' => 'Time to post', 'body' => '@acmecoffee’s story is ready', 'url' => $url], $message->values);
    }

    public function test_whatsapp_uses_the_dedicated_template_once_it_is_configured(): void
    {
        config(['services.whatsapp.templates.story_ready_to_post' => 'HX0123456789']);
        $assignee = $this->member(Role::Designer);
        $handoff = $this->handoff(['assignee_id' => $assignee->id]);
        $url = app(IssueStoryHandoffLinkAction::class)($handoff);

        $message = (new StoryPublishHandoffReady($handoff->id, $url))->toWhatsApp($assignee);

        $this->assertSame(MessageTemplate::StoryReadyToPost, $message->template);
        $this->assertSame(
            ['account' => '@acmecoffee', 'due_at' => 'Fri 20 Nov, 10:00 (UTC)', 'url' => $url],
            $message->values,
        );
    }

    // --- The link -----------------------------------------------------------------------------

    public function test_the_nudge_carries_the_same_link_as_the_first_notification_and_both_keep_working(): void
    {
        Notification::fake();
        $assignee = $this->member(Role::Designer);
        PushSubscription::factory()->create(['user_id' => $assignee->id]);
        $handoff = $this->handoff(['assignee_id' => $assignee->id]);
        $hash = $handoff->token_hash;

        app(NotifyStoryPosterAction::class)($handoff);
        $first = $this->tokenFrom($this->push->sent[0]['message']->url);

        app(NotifyStoryPosterAction::class)($handoff, nudge: true);
        $nudge = $this->tokenFrom($this->push->sent[1]['message']->url);

        // One link per publish attempt: the nudge does not replace the email already in an inbox.
        $this->assertSame($first, $nudge);
        // Only the hash is stored, and it has not moved since the handoff was opened.
        $this->assertSame($hash, $handoff->refresh()->token_hash);
        $this->assertSame(hash('sha256', $first), $handoff->token_hash);
        $this->assertSame($handoff->id, app(ShowStoryHandoffAction::class)->resolve($first)->id);

        // The emailed link (what the notification itself was built with) is that same one.
        Notification::assertSentTo($assignee, StoryPublishHandoffReady::class, function (StoryPublishHandoffReady $notification) use ($assignee, $first) {
            return str_contains((string) $notification->toMail($assignee)->render(), route('story-handoff.show', ['token' => $first]));
        });
    }

    // --- Push ---------------------------------------------------------------------------------

    public function test_the_push_goes_to_the_posters_subscriptions_only(): void
    {
        Notification::fake();
        $owner = $this->member(Role::Owner);
        $assignee = $this->member(Role::Designer);
        PushSubscription::factory()->create(['user_id' => $owner->id]);
        $phone = PushSubscription::factory()->create(['user_id' => $assignee->id]);
        $laptop = PushSubscription::factory()->create(['user_id' => $assignee->id]);
        $handoff = $this->handoff(['assignee_id' => $assignee->id]);

        app(NotifyStoryPosterAction::class)($handoff);

        $this->assertEqualsCanonicalizing([$phone->id, $laptop->id], array_column($this->push->sent, 'subscription_id'));
        $message = $this->push->sent[0]['message'];
        $this->assertSame('Time to post.', $message->title);
        $this->assertSame('@acmecoffee’s story is ready.', $message->body);
        $this->assertSame('story-handoff-'.$handoff->id, $message->tag);
        // The push carries the working link: the same token the handoff now holds the hash of.
        $this->assertSame(hash('sha256', $this->tokenFrom($message->url)), $handoff->refresh()->token_hash);
    }

    public function test_a_nudge_push_replaces_the_first_one_by_reusing_its_tag(): void
    {
        $assignee = $this->member(Role::Designer);
        PushSubscription::factory()->create(['user_id' => $assignee->id]);
        $handoff = $this->handoff(['assignee_id' => $assignee->id]);
        $send = app(SendStoryHandoffPushAction::class);

        $this->assertSame(1, $send($handoff, 'https://alima.test/story/first', false, collect([$assignee])));
        $this->assertSame(1, $send($handoff, 'https://alima.test/story/second', true, collect([$assignee])));

        [$first, $nudge] = array_column($this->push->sent, 'message');
        $this->assertSame('story-handoff-'.$handoff->id, $first->tag);
        $this->assertSame($first->tag, $nudge->tag);
        $this->assertSame('Still to post.', $nudge->title);
        $this->assertSame('@acmecoffee’s story is still waiting to be posted.', $nudge->body);
        $this->assertSame('https://alima.test/story/second', $nudge->url);
    }

    public function test_a_gone_push_subscription_is_deleted(): void
    {
        $assignee = $this->member(Role::Designer);
        $dead = PushSubscription::factory()->create(['user_id' => $assignee->id]);
        $alive = PushSubscription::factory()->create(['user_id' => $assignee->id]);
        $this->push->goneEndpoints = [$dead->endpoint];
        $handoff = $this->handoff(['assignee_id' => $assignee->id]);

        $delivered = app(SendStoryHandoffPushAction::class)($handoff, 'https://alima.test/story/abc', false, collect([$assignee]));

        $this->assertSame(1, $delivered);
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $dead->id]);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $alive->id]);
    }

    public function test_nothing_is_pushed_when_push_is_not_configured(): void
    {
        Notification::fake();
        $this->push->configured = false;
        $assignee = $this->member(Role::Designer);
        $subscription = PushSubscription::factory()->create(['user_id' => $assignee->id]);
        $handoff = $this->handoff(['assignee_id' => $assignee->id]);

        app(NotifyStoryPosterAction::class)($handoff);

        $this->assertSame([], $this->push->sent);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $subscription->id]);
        Notification::assertSentTo($assignee, StoryPublishHandoffReady::class);
    }

    // --- The token stays out of logs and webhooks ---------------------------------------------

    public function test_no_log_line_contains_the_token(): void
    {
        Notification::fake();
        $lines = [];
        Log::listen(function (MessageLogged $event) use (&$lines) {
            $lines[] = $event->level.' '.$event->message.' '.json_encode($event->context);
        });

        $assignee = $this->member(Role::Designer);
        PushSubscription::factory()->create(['user_id' => $assignee->id]);
        $handoff = $this->handoff(['assignee_id' => $assignee->id]);

        app(NotifyStoryPosterAction::class)($handoff, late: true);
        app(NotifyStoryPosterAction::class)($handoff, nudge: true);

        $this->assertCount(2, $this->push->sent);
        $logged = str_replace('\\/', '/', implode("\n", $lines));
        $this->assertStringContainsString('Story poster notified', $logged);
        $this->assertStringContainsString('Story handoff push sent', $logged);

        foreach ($this->push->sent as $sent) {
            $token = $this->tokenFrom($sent['message']->url);
            $this->assertSame(64, strlen($token));
            $this->assertStringNotContainsString($token, $logged);
        }

        $this->assertStringNotContainsString('/story/', $logged);
    }

    public function test_the_ready_to_post_webhook_fires_on_the_first_notification_only_and_carries_no_token(): void
    {
        Notification::fake();
        Queue::fake([DeliverWebhookJob::class]);
        WebhookEndpoint::create([
            'org_id' => $this->org->id,
            'url' => 'https://hooks.example.test/alima',
            'secret' => 'secret-value',
            'events' => [WebhookEvent::StoryReadyToPost->value],
        ]);
        // Subscribed to something else: it must not hear about this.
        WebhookEndpoint::create([
            'org_id' => $this->org->id,
            'url' => 'https://hooks.example.test/other',
            'secret' => 'secret-value',
            'events' => [WebhookEvent::PostApproved->value],
        ]);
        $assignee = $this->member(Role::Designer);
        PushSubscription::factory()->create(['user_id' => $assignee->id]);
        $handoff = $this->handoff(['assignee_id' => $assignee->id]);

        app(NotifyStoryPosterAction::class)($handoff);
        app(NotifyStoryPosterAction::class)($handoff, nudge: true);

        $delivery = WebhookDelivery::query()->withoutOrgScope()->sole();
        Queue::assertPushed(DeliverWebhookJob::class, 1);
        $this->assertSame('story.ready_to_post', $delivery->event);
        $this->assertSame('story.ready_to_post', $delivery->payload['event']);

        $data = $delivery->payload['data'];
        $this->assertSame($handoff->post_id, $data['post_id']);
        $this->assertSame($this->client->id, $data['client_id']);
        $this->assertSame($handoff->post_target_id, $data['post_target_id']);
        $this->assertSame('instagram', $data['platform']);
        $this->assertSame('@acmecoffee', $data['handle']);
        $this->assertSame('team', $data['posted_by']);
        $this->assertSame($handoff->due_at->toIso8601String(), $data['due_at']);
        $this->assertSame(
            route('posts.story-handoff.open', ['post' => $handoff->post_id, 'target' => $handoff->post_target_id]),
            $data['url'],
        );

        $stored = str_replace('\\/', '/', (string) json_encode($delivery->payload));
        $this->assertStringNotContainsString('/story/', $stored);

        foreach ($this->push->sent as $sent) {
            $this->assertStringNotContainsString($this->tokenFrom($sent['message']->url), $stored);
        }
    }
}
