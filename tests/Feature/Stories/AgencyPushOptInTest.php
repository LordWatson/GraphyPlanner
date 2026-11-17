<?php

namespace Tests\Feature\Stories;

use App\Actions\Push\SendNothingScheduledTomorrowPushAction;
use App\Actions\Push\SendPostPushRemindersAction;
use App\Actions\Push\SendPublishFailurePushAction;
use App\Contracts\PushNotifier;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PushSubscription;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakePushNotifier;
use Tests\TestCase;

/**
 * Step S-3.2 — the web-push opt-in is open to agency members (so "a story is ready to post" can
 * buzz a phone), closed to client reviewers, and the business-only reminder pushes stay
 * business-only now that an agency member can hold a subscription.
 */
class AgencyPushOptInTest extends TestCase
{
    use RefreshDatabase;

    private FakePushNotifier $notifier;

    private Organization $agency;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notifier = new FakePushNotifier;
        $this->app->instance(PushNotifier::class, $this->notifier);

        $this->agency = Organization::factory()->create([
            'default_timezone' => 'Europe/London',
            'onboarding_completed_at' => now(),
        ]);
        $this->client = Client::factory()->for($this->agency, 'organization')->create();
    }

    private function member(Role $role = Role::Strategist): User
    {
        return User::factory()->for($this->agency, 'organization')->role($role)->create([
            'client_id' => $role === Role::ClientReviewer ? $this->client->id : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionPayload(): array
    {
        return [
            'endpoint' => 'https://push.example/agency',
            'keys' => ['p256dh' => 'BPublicKeyBytes', 'auth' => 'AuthSecret'],
            'contentEncoding' => 'aes128gcm',
        ];
    }

    public function test_an_agency_member_subscribes_and_unsubscribes_a_browser(): void
    {
        $member = $this->member();

        $this->actingAs($member)
            ->postJson(route('push.subscribe'), $this->subscriptionPayload())
            ->assertCreated();

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $member->id,
            'endpoint_hash' => PushSubscription::hashEndpoint('https://push.example/agency'),
        ]);

        $this->actingAs($member)
            ->deleteJson(route('push.unsubscribe'), ['endpoint' => 'https://push.example/agency'])
            ->assertOk();

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_the_opt_in_keeps_the_url_business_browsers_already_use(): void
    {
        $this->assertSame('/push/subscribe', route('push.subscribe', absolute: false));
        $this->assertSame('/push/subscribe', route('push.unsubscribe', absolute: false));
    }

    public function test_a_client_reviewer_cannot_subscribe(): void
    {
        $reviewer = $this->member(Role::ClientReviewer);

        $this->actingAs($reviewer)
            ->postJson(route('push.subscribe'), $this->subscriptionPayload())
            ->assertRedirect(route('portal.dashboard'));

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_a_guest_cannot_subscribe(): void
    {
        $this->postJson(route('push.subscribe'), $this->subscriptionPayload())->assertUnauthorized();
    }

    public function test_the_push_config_is_shared_with_an_agency_member(): void
    {
        $this->actingAs($this->member())
            ->get(route('notifications.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pwa.pushEnabled', true)
                ->where('pwa.pushPublicKey', 'BFakePublicKeyForTests')
                ->where('workspace.type', 'agency'));
    }

    public function test_the_push_config_is_not_shared_with_a_client_reviewer(): void
    {
        $this->actingAs($this->member(Role::ClientReviewer))
            ->get(route('notifications.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pwa', null));
    }

    public function test_the_goes_out_soon_push_still_skips_a_subscribed_agency_member(): void
    {
        $member = $this->member(Role::Owner);
        PushSubscription::factory()->for($member)->create();
        $this->scheduledPost(dueInMinutes: 20);

        $this->assertSame(0, app(SendPostPushRemindersAction::class)());
        $this->assertSame([], $this->notifier->sent);
    }

    public function test_the_nothing_scheduled_tomorrow_push_still_skips_a_subscribed_agency_member(): void
    {
        $member = $this->member(Role::Owner);
        PushSubscription::factory()->for($member)->create();
        // A connected account and an empty tomorrow: exactly what nudges a business workspace.
        $this->account();

        $this->assertSame(0, app(SendNothingScheduledTomorrowPushAction::class)());
        $this->assertSame([], $this->notifier->sent);
    }

    public function test_the_publish_failure_push_still_skips_a_subscribed_agency_member(): void
    {
        $member = $this->member(Role::Owner);
        PushSubscription::factory()->for($member)->create();
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->agency->id,
            'status' => PostStatus::Failed,
            'created_by' => $member->id,
        ]);

        app(SendPublishFailurePushAction::class)($post);

        $this->assertSame([], $this->notifier->sent);
    }

    private function account(): SocialAccount
    {
        return SocialAccount::factory()->for($this->client)->connected()->create([
            'org_id' => $this->agency->id,
            'platform' => Platform::Instagram,
            'timezone' => 'UTC',
        ]);
    }

    private function scheduledPost(int $dueInMinutes): Post
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->agency->id,
            'status' => PostStatus::Scheduled,
        ]);

        $post->targets()->create([
            'social_account_id' => $this->account()->id,
            'scheduled_local_date' => now()->addMinutes($dueInMinutes)->toDateString(),
            'scheduled_local_time' => now()->addMinutes($dueInMinutes)->format('H:i'),
            'scheduled_at_utc' => now()->addMinutes($dueInMinutes),
        ]);

        return $post;
    }
}
