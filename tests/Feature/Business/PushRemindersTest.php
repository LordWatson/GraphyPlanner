<?php

namespace Tests\Feature\Business;

use App\Actions\Push\SendPostPushRemindersAction;
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
use Tests\Support\FakePushNotifier;
use Tests\TestCase;

/**
 * Step B-7.4 — per-browser push: subscription storage, opt-out, and the "goes out soon" reminder
 * sweep, all against a faked transport.
 */
class PushRemindersTest extends TestCase
{
    use RefreshDatabase;

    private FakePushNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notifier = new FakePushNotifier;
        $this->app->instance(PushNotifier::class, $this->notifier);
    }

    private function owner(): User
    {
        $organization = Organization::factory()->business()->create(['default_timezone' => 'Europe/London']);
        Client::factory()->for($organization, 'organization')->primary()->create();

        return User::factory()->for($organization, 'organization')->role(Role::Owner)->create();
    }

    private function subscriptionPayload(string $endpoint = 'https://push.example/one'): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'BPublicKeyBytes', 'auth' => 'AuthSecret'],
            'contentEncoding' => 'aes128gcm',
        ];
    }

    public function test_a_user_subscribes_the_browser(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->postJson(route('business.push.subscribe'), $this->subscriptionPayload())
            ->assertCreated();

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $owner->id,
            'endpoint_hash' => PushSubscription::hashEndpoint('https://push.example/one'),
        ]);
    }

    public function test_re_subscribing_the_same_browser_updates_in_place(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->postJson(route('business.push.subscribe'), $this->subscriptionPayload())->assertCreated();
        $this->actingAs($owner)->postJson(route('business.push.subscribe'), [
            ...$this->subscriptionPayload(),
            'keys' => ['p256dh' => 'NewKey', 'auth' => 'NewAuth'],
        ])->assertCreated();

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertSame('NewKey', PushSubscription::query()->sole()->public_key);
    }

    public function test_a_user_opts_out(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->postJson(route('business.push.subscribe'), $this->subscriptionPayload())->assertCreated();

        $this->actingAs($owner)
            ->deleteJson(route('business.push.unsubscribe'), ['endpoint' => 'https://push.example/one'])
            ->assertOk();

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_subscribe_404s_when_push_is_not_configured(): void
    {
        $this->notifier->configured = false;
        $owner = $this->owner();

        $this->actingAs($owner)
            ->postJson(route('business.push.subscribe'), $this->subscriptionPayload())
            ->assertNotFound();
    }

    public function test_reminder_sweep_pushes_once_for_a_post_due_soon(): void
    {
        [$owner, $post] = $this->scheduledPost(dueInMinutes: 20);
        PushSubscription::factory()->for($owner)->create(['endpoint' => 'https://push.example/one']);

        $count = app(SendPostPushRemindersAction::class)();

        $this->assertSame(1, $count);
        $this->assertCount(1, $this->notifier->sent);
        $this->assertNotNull($post->targets()->sole()->push_reminder_sent_at);

        // Idempotent — a second sweep finds nothing new.
        $this->assertSame(0, app(SendPostPushRemindersAction::class)());
        $this->assertCount(1, $this->notifier->sent);
    }

    public function test_a_post_outside_the_lead_window_is_not_reminded(): void
    {
        [$owner] = $this->scheduledPost(dueInMinutes: 180);
        PushSubscription::factory()->for($owner)->create();

        $this->assertSame(0, app(SendPostPushRemindersAction::class)());
        $this->assertCount(0, $this->notifier->sent);
    }

    public function test_a_gone_subscription_is_pruned(): void
    {
        [$owner] = $this->scheduledPost(dueInMinutes: 20);
        PushSubscription::factory()->for($owner)->create(['endpoint' => 'https://push.example/gone']);
        $this->notifier->goneEndpoints = ['https://push.example/gone'];

        app(SendPostPushRemindersAction::class)();

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_reminders_do_nothing_when_push_is_unconfigured(): void
    {
        $this->notifier->configured = false;
        [$owner] = $this->scheduledPost(dueInMinutes: 20);
        PushSubscription::factory()->for($owner)->create();

        $this->assertSame(0, app(SendPostPushRemindersAction::class)());
    }

    /**
     * @return array{0: User, 1: Post}
     */
    private function scheduledPost(int $dueInMinutes): array
    {
        $organization = Organization::factory()->business()->create();
        $brand = Client::factory()->for($organization, 'organization')->primary()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        $account = SocialAccount::factory()->for($brand)->connected()->create([
            'org_id' => $organization->id,
            'platform' => Platform::Instagram,
            'timezone' => 'UTC',
        ]);

        $post = Post::factory()->for($brand)->create([
            'org_id' => $organization->id,
            'status' => PostStatus::Scheduled,
        ]);

        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => now()->addMinutes($dueInMinutes)->toDateString(),
            'scheduled_local_time' => now()->addMinutes($dueInMinutes)->format('H:i'),
            'scheduled_at_utc' => now()->addMinutes($dueInMinutes),
        ]);

        return [$owner, $post->refresh()];
    }
}
