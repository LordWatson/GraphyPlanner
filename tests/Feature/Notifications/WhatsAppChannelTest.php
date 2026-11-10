<?php

namespace Tests\Feature\Notifications;

use App\Actions\Posts\SendClientReviewRequestedEmailAction;
use App\Contracts\WhatsAppClient;
use App\Enums\NotificationCategory;
use App\Enums\WhatsAppMessageStatus;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSuppression;
use App\Notifications\Channels\WhatsAppChannel;
use App\Support\Notifications\NotificationPreferences;
use App\Support\WhatsApp\MessageTemplate;
use App\Support\WhatsApp\WhatsAppFailure;
use App\Support\WhatsApp\WhatsAppSendResult;
use App\Support\WhatsApp\WhatsAppTemplateMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Steps W-0.3, W-0.4, W-0.5, W-1.2, W-1.3 and W-3.1 — routing, preferences, consent, suppression
 * and the message log, all at the one place they are enforced: the channel.
 *
 * The property under test throughout is **that nobody gets messaged by accident**: an opt-in with
 * no verified number sends nothing, a verified number with no opt-in sends nothing, a suppressed
 * number sends nothing whatever either of them says, and the settings form cannot turn an opt-in
 * off by not mentioning it.
 */
class WhatsAppChannelTest extends TestCase
{
    use RefreshDatabase;

    private RecordingWhatsAppClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new RecordingWhatsAppClient;
        $this->app->instance(WhatsAppClient::class, $this->client);
        RateLimiter::clear('whatsapp-send:1');
    }

    private function optedIn(User $user, NotificationCategory $category = NotificationCategory::Approvals): User
    {
        $preferences = $user->notification_preferences ?? [];
        $preferences[$category->value]['whatsapp'] = true;
        $user->update(['notification_preferences' => $preferences]);

        return $user;
    }

    private function consentingContact(string $phone = '+447700900124'): ClientContact
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->for($organization, 'organization')->create();

        $contact = ClientContact::factory()->create([
            'org_id' => $organization->id,
            'client_id' => $client->id,
            'phone' => $phone,
        ]);

        $contact->setWhatsAppConsent(true, 'staff');
        $contact->save();

        return $contact;
    }

    public function test_a_number_only_routes_once_it_has_been_verified(): void
    {
        $unverified = User::factory()->create(['phone' => '+447700900123']);
        $this->assertNull($unverified->routeNotificationForWhatsApp());

        $verified = User::factory()->whatsAppVerified()->create();
        $this->assertSame('+447700900123', $verified->routeNotificationForWhatsApp());

        // Verified, but saved in a national format nobody can dial internationally.
        $national = User::factory()->whatsAppVerified('07700 900123')->create();
        $this->assertNull($national->routeNotificationForWhatsApp());

        $this->assertNull(User::factory()->create()->routeNotificationForWhatsApp());
    }

    public function test_whatsapp_is_off_for_every_category_by_default(): void
    {
        $user = User::factory()->whatsAppVerified()->create();

        foreach (NotificationCategory::cases() as $category) {
            $this->assertFalse(
                NotificationPreferences::wants($user, $category, 'whatsapp'),
                "{$category->value} defaults to WhatsApp on",
            );
        }

        $this->assertContains('whatsapp', NotificationPreferences::CHANNELS);
    }

    public function test_wanting_whatsapp_needs_both_the_opt_in_and_a_verified_number(): void
    {
        $notification = WhatsAppTestNotification::class;

        $neither = User::factory()->create();
        $this->assertFalse(NotificationPreferences::wantsWhatsApp($neither, $notification));

        $numberOnly = User::factory()->whatsAppVerified()->create();
        $this->assertFalse(NotificationPreferences::wantsWhatsApp($numberOnly, $notification));

        // Opt in to the category this notification actually falls under, not a guess at it.
        $category = NotificationCategory::forNotificationClass($notification);

        $optInOnly = $this->optedIn(User::factory()->create(), $category);
        $this->assertFalse(NotificationPreferences::wantsWhatsApp($optInOnly, $notification));

        $both = $this->optedIn(User::factory()->whatsAppVerified()->create(), $category);
        $this->assertTrue(NotificationPreferences::wantsWhatsApp($both, $notification));
    }

    public function test_the_settings_form_cannot_switch_off_an_opt_in_it_does_not_render(): void
    {
        $user = $this->optedIn(User::factory()->whatsAppVerified()->create());

        // Exactly what `settings/notifications.tsx` submits for a user with no verified number:
        // in_app and digest only.
        $payload = [];

        foreach (NotificationCategory::cases() as $category) {
            $payload[$category->value] = ['in_app' => true, 'digest' => false];
        }

        $this->actingAs($user)
            ->put(route('notifications.update'), ['preferences' => $payload])
            ->assertRedirect();

        $this->assertTrue(NotificationPreferences::wants($user->refresh(), NotificationCategory::Approvals, 'whatsapp'));
        $this->assertFalse(NotificationPreferences::wants($user, NotificationCategory::Approvals, 'digest'));
    }

    public function test_an_explicit_opt_out_is_still_honoured(): void
    {
        $user = $this->optedIn(User::factory()->whatsAppVerified()->create());

        $this->actingAs($user)
            ->put(route('notifications.update'), [
                'preferences' => [
                    NotificationCategory::Approvals->value => ['in_app' => true, 'digest' => true, 'whatsapp' => false],
                ],
            ])
            ->assertRedirect();

        $this->assertFalse(NotificationPreferences::wants($user->refresh(), NotificationCategory::Approvals, 'whatsapp'));
    }

    public function test_the_channel_sends_the_notifications_own_template(): void
    {
        $user = User::factory()->whatsAppVerified()->create();

        $user->notify(new WhatsAppTestNotification);

        $this->assertCount(1, $this->client->sent);
        [$organization, $to, $message] = $this->client->sent[0];

        $this->assertSame($user->org_id, $organization->id);
        $this->assertSame('+447700900123', $to);
        $this->assertSame(MessageTemplate::PostWaitingClient, $message->template);
        $this->assertSame(
            ['client_name' => 'Jess', 'post_summary' => 'Spring menu teaser', 'review_url' => 'https://alima.test/review/abc'],
            $message->values,
        );
        // Positional, in the order the approved copy expects.
        $this->assertSame(
            '{"1":"Jess","2":"Spring menu teaser","3":"https:\/\/alima.test\/review\/abc"}',
            $message->contentVariables(),
        );
    }

    public function test_a_notification_without_its_own_template_falls_back_to_the_generic_one(): void
    {
        $user = User::factory()->whatsAppVerified()->create();

        $user->notify(new WhatsAppDefaultTemplateNotification);

        [, , $message] = $this->client->sent[0];

        $this->assertSame(MessageTemplate::Notification, $message->template);
        $this->assertSame(
            ['title' => 'Post ready for review', 'body' => 'A post is waiting for your review', 'url' => 'https://alima.test/posts/1'],
            $message->values,
        );
    }

    public function test_a_template_filled_with_the_wrong_variables_is_a_programming_error(): void
    {
        // Step W-1.4's guard: a hole in an approved template would reach a client as `{{2}}`.
        $this->expectException(\InvalidArgumentException::class);

        MessageTemplate::PostWaitingClient->fill(['client_name' => 'Jess']);
    }

    public function test_a_recipient_with_no_verified_number_is_a_silent_no_op(): void
    {
        User::factory()->create(['phone' => '+447700900123'])->notify(new WhatsAppTestNotification);

        $this->assertSame([], $this->client->sent);
    }

    public function test_an_on_demand_route_with_no_organization_sends_nothing(): void
    {
        // Credentials are resolved per organization, so an anonymous notifiable cannot be sent to.
        NotificationFacade::route('whatsapp', '+447700900123')->notify(new WhatsAppTestNotification);

        $this->assertSame([], $this->client->sent);
        $this->assertSame(0, WhatsAppMessage::query()->withoutOrgScope()->count());
    }

    public function test_a_client_contact_routes_only_with_consent(): void
    {
        $contact = $this->consentingContact();
        $this->assertSame('+447700900124', $contact->routeNotificationForWhatsApp());

        $contact->notify(new WhatsAppTestNotification);
        $this->assertCount(1, $this->client->sent);

        $contact->setWhatsAppConsent(false, 'staff');
        $contact->save();

        $this->assertNull($contact->routeNotificationForWhatsApp());
        $contact->notify(new WhatsAppTestNotification);
        $this->assertCount(1, $this->client->sent, 'A contact was messaged after withdrawing consent.');
    }

    public function test_consent_records_when_it_was_given_and_how(): void
    {
        $contact = $this->consentingContact();

        $this->assertTrue($contact->receives_whatsapp);
        $this->assertNotNull($contact->whatsapp_consent_at);
        $this->assertSame('staff', $contact->whatsapp_consent_source);
        $this->assertTrue(ClientContact::query()->withoutOrgScope()->receivingWhatsApp()->whereKey($contact->id)->exists());
    }

    public function test_a_suppressed_number_is_never_messaged_whatever_the_preference_says(): void
    {
        $user = User::factory()->whatsAppVerified()->create();
        WhatsAppSuppression::suppress('+447700900123', WhatsAppSuppression::REASON_STOP);

        $user->notify(new WhatsAppTestNotification);

        $this->assertSame([], $this->client->sent);

        $row = WhatsAppMessage::query()->withoutOrgScope()->firstOrFail();
        $this->assertSame(WhatsAppMessageStatus::Skipped, $row->status);
        $this->assertSame('number is suppressed', $row->error_reason);
    }

    public function test_a_permanently_undeliverable_number_suppresses_itself(): void
    {
        $this->client->result = WhatsAppSendResult::failed('63024', 'No WhatsApp account.', WhatsAppFailure::Permanent);
        $user = User::factory()->whatsAppVerified()->create();

        $user->notify(new WhatsAppTestNotification);

        $this->assertTrue(WhatsAppSuppression::isSuppressed('+447700900123', $user->org_id));

        // And the next one does not even reach Twilio.
        $this->client->sent = [];
        $user->notify(new WhatsAppTestNotification);
        $this->assertSame([], $this->client->sent);
    }

    public function test_every_send_attempt_is_recorded_in_the_message_log(): void
    {
        $user = User::factory()->whatsAppVerified()->create();

        $user->notify(new WhatsAppTestNotification);

        $row = WhatsAppMessage::query()->withoutOrgScope()->firstOrFail();

        $this->assertSame($user->org_id, $row->org_id);
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame(WhatsAppMessageStatus::Sent, $row->status);
        $this->assertSame(MessageTemplate::PostWaitingClient->value, $row->template);
        $this->assertSame('wamid.test', $row->provider_message_id);
        $this->assertSame(WhatsAppTestNotification::class, $row->notification_type);
        // The log shows four digits and the model hides the rest from any serialisation.
        $this->assertSame('***0123', $row->maskedPhone());
        $this->assertArrayNotHasKey('to_phone', $row->toArray());
    }

    public function test_a_failed_send_is_recorded_with_its_reason(): void
    {
        $this->client->result = WhatsAppSendResult::failed('63016', 'The 24-hour reply window has closed.', WhatsAppFailure::Window);
        $user = User::factory()->whatsAppVerified()->create();

        $user->notify(new WhatsAppTestNotification);

        $row = WhatsAppMessage::query()->withoutOrgScope()->firstOrFail();
        $this->assertSame(WhatsAppMessageStatus::Failed, $row->status);
        $this->assertSame('63016', $row->error_code);
        // A window failure must not suppress: the number is fine, the moment was wrong.
        $this->assertFalse(WhatsAppSuppression::isSuppressed('+447700900123', $user->org_id));
    }

    public function test_an_organization_cannot_flood_whatsapp(): void
    {
        config(['services.whatsapp.per_minute' => 2]);
        $user = User::factory()->whatsAppVerified()->create();

        for ($i = 0; $i < 4; $i++) {
            $user->notify(new WhatsAppTestNotification);
        }

        $this->assertCount(2, $this->client->sent);
        $this->assertSame(2, WhatsAppMessage::query()->withoutOrgScope()
            ->where('status', WhatsAppMessageStatus::Skipped)->count());
    }

    /**
     * Step W-2.1 end to end: moving a post to "waiting on client" is what sends the approval, and
     * the link a WhatsApp-only contact gets has to be one they can actually open.
     */
    public function test_moving_a_post_to_waiting_client_whatsapps_the_consenting_contact(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->for($organization, 'organization')->create();

        // Consented to WhatsApp and *not* on the review-email list: the case that used to hand
        // somebody with no login a portal URL, and before that sent them nothing at all.
        $contact = ClientContact::factory()->create([
            'org_id' => $organization->id,
            'client_id' => $client->id,
            'name' => 'Jess',
            'phone' => '+447700900124',
            'receives_review_emails' => false,
        ]);
        $contact->setWhatsAppConsent(true, 'staff');
        $contact->save();

        $post = Post::factory()->create([
            'org_id' => $organization->id,
            'client_id' => $client->id,
            'master_caption' => 'Spring menu teaser',
        ]);

        app(SendClientReviewRequestedEmailAction::class)($post);

        $this->assertCount(1, $this->client->sent);
        [$sentOrganization, $to, $message] = $this->client->sent[0];

        $this->assertSame($organization->id, $sentOrganization->id);
        $this->assertSame('+447700900124', $to);
        $this->assertSame(MessageTemplate::PostWaitingClient, $message->template);
        $this->assertSame('Jess', $message->values['client_name']);
        $this->assertSame('Spring menu teaser', $message->values['post_summary']);
        // The token link, not `portal.posts.show` — this contact has no login to land on.
        $this->assertStringContainsString('/review/', $message->values['review_url']);
    }

    public function test_a_client_with_no_contacts_on_either_channel_is_skipped(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->for($organization, 'organization')->create(['approval_email' => null]);
        $post = Post::factory()->create(['org_id' => $organization->id, 'client_id' => $client->id]);

        $this->assertNull(app(SendClientReviewRequestedEmailAction::class)($post));
        $this->assertSame([], $this->client->sent);
    }

    public function test_the_channel_is_registered_under_the_name_notifications_ask_for(): void
    {
        $this->assertInstanceOf(WhatsAppChannel::class, NotificationFacade::driver('whatsapp'));
    }
}

/**
 * Declares its own template, and asks for WhatsApp whatever the recipient prefers — the channel,
 * not `via()`, is what these tests are about.
 */
class WhatsAppTestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['whatsapp'];
    }

    public function toWhatsApp(object $notifiable): WhatsAppTemplateMessage
    {
        return MessageTemplate::PostWaitingClient->fill([
            'client_name' => 'Jess',
            // Deliberately dirty: Meta refuses a variable with a newline in it.
            'post_summary' => "Spring   menu\nteaser",
            'review_url' => 'https://alima.test/review/abc',
        ]);
    }
}

/**
 * The shape every existing notification already has: a title, a body and a url in `toArray()`.
 */
class WhatsAppDefaultTemplateNotification extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['whatsapp'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Post ready for review',
            'body' => 'A post is waiting for your review',
            'url' => 'https://alima.test/posts/1',
        ];
    }
}

/**
 * Records instead of sending. The Twilio client has its own tests against a faked Twilio; what
 * matters here is which organization, number and template the channel chose.
 */
class RecordingWhatsAppClient implements WhatsAppClient
{
    /** @var list<array{0: Organization, 1: string, 2: WhatsAppTemplateMessage}> */
    public array $sent = [];

    public ?WhatsAppSendResult $result = null;

    public function sendTemplate(Organization $organization, string $toPhone, WhatsAppTemplateMessage $message): WhatsAppSendResult
    {
        $this->sent[] = [$organization, $toPhone, $message];

        return $this->result ?? WhatsAppSendResult::sent('wamid.test', 'queued');
    }

    public function sendSessionText(Organization $organization, string $toPhone, string $body): WhatsAppSendResult
    {
        return $this->result ?? WhatsAppSendResult::sent('wamid.test');
    }

    public function verifyCredentials(Organization $organization): bool
    {
        return true;
    }
}
