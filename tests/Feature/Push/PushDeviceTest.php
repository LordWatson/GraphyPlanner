<?php

namespace Tests\Feature\Push;

use App\Actions\Push\SendStoryHandoffPushAction;
use App\Contracts\PushNotifier;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\StoryHandoff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakePushNotifier;
use Tests\TestCase;

/**
 * Native shell (Capacitor) — a phone registers, refreshes and removes its FCM device token through
 * the same opt-in rules as a browser: members only, never a client reviewer, only your own rows.
 */
class PushDeviceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $agency;

    protected function setUp(): void
    {
        parent::setUp();

        config(['push.enabled' => true, 'push.fcm.transport' => 'log']);

        $this->agency = Organization::factory()->create(['onboarding_completed_at' => now()]);
    }

    private function member(Role $role = Role::Strategist, ?Organization $organization = null): User
    {
        $organization ??= $this->agency;

        return User::factory()->for($organization, 'organization')->role($role)->create([
            'client_id' => $role === Role::ClientReviewer
                ? Client::factory()->for($organization, 'organization')->create()->id
                : null,
        ]);
    }

    public function test_a_member_registers_a_device_token(): void
    {
        $member = $this->member();

        $this->actingAs($member)
            ->postJson(route('push.devices.store'), ['token' => 'fcm-token-1', 'platform' => 'android'])
            ->assertCreated();

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $member->id,
            'platform' => 'android',
            'endpoint' => 'fcm-token-1',
            'public_key' => null,
        ]);
    }

    public function test_registering_the_same_token_again_refreshes_it_and_moves_it_to_the_current_user(): void
    {
        $first = $this->member();
        $second = $this->member(organization: Organization::factory()->create(['onboarding_completed_at' => now()]));

        $this->actingAs($first)->postJson(route('push.devices.store'), ['token' => 'shared-phone', 'platform' => 'ios'])->assertCreated();

        $this->travel(1)->days();
        $this->actingAs($first)->postJson(route('push.devices.store'), ['token' => 'shared-phone', 'platform' => 'ios'])->assertCreated();

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertTrue(PushSubscription::query()->sole()->updated_at->isSameDay(now()));

        $this->actingAs($second)->postJson(route('push.devices.store'), ['token' => 'shared-phone', 'platform' => 'ios'])->assertCreated();

        $this->assertSame($second->id, PushSubscription::query()->sole()->user_id);
    }

    public function test_the_token_and_platform_are_validated(): void
    {
        $this->actingAs($this->member())
            ->postJson(route('push.devices.store'), ['token' => '', 'platform' => 'windows'])
            ->assertJsonValidationErrors(['token', 'platform']);
    }

    public function test_a_guest_cannot_register(): void
    {
        $this->postJson(route('push.devices.store'), ['token' => 't', 'platform' => 'android'])->assertUnauthorized();
    }

    public function test_a_client_reviewer_cannot_register(): void
    {
        $this->actingAs($this->member(Role::ClientReviewer))
            ->postJson(route('push.devices.store'), ['token' => 't', 'platform' => 'android'])
            ->assertRedirect(route('portal.dashboard'));

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_registration_404s_when_native_push_is_off(): void
    {
        config(['push.enabled' => false]);

        $this->actingAs($this->member())
            ->postJson(route('push.devices.store'), ['token' => 't', 'platform' => 'android'])
            ->assertNotFound();

        // `fcm` transport with no service-account file is not configured either.
        config(['push.enabled' => true, 'push.fcm.transport' => 'fcm', 'push.fcm.credentials' => null]);

        $this->actingAs($this->member())
            ->postJson(route('push.devices.store'), ['token' => 't', 'platform' => 'android'])
            ->assertNotFound();
    }

    public function test_a_member_removes_only_their_own_token(): void
    {
        $member = $this->member();
        $other = $this->member(organization: Organization::factory()->create(['onboarding_completed_at' => now()]));
        PushSubscription::factory()->for($other)->create([
            'platform' => 'android',
            'endpoint' => 'others-token',
            'endpoint_hash' => PushSubscription::hashEndpoint('others-token'),
        ]);

        $this->actingAs($member)->postJson(route('push.devices.store'), ['token' => 'mine', 'platform' => 'android']);

        $this->actingAs($member)
            ->deleteJson(route('push.devices.destroy'), ['token' => 'others-token'])
            ->assertOk()
            ->assertJson(['deleted' => 0]);

        $this->actingAs($member)
            ->deleteJson(route('push.devices.destroy'), ['token' => 'mine'])
            ->assertOk()
            ->assertJson(['deleted' => 1]);

        $this->assertSame(['others-token'], PushSubscription::query()->pluck('endpoint')->all());
    }

    public function test_logging_out_drops_this_phones_token_and_keeps_the_users_other_subscriptions(): void
    {
        $member = $this->member();
        $browser = PushSubscription::factory()->for($member)->create();

        $this->actingAs($member)->postJson(route('push.devices.store'), ['token' => 'this-phone', 'platform' => 'ios'])->assertCreated();
        $this->post(route('logout'));

        $this->assertGuest();
        $this->assertSame([$browser->id], PushSubscription::query()->pluck('id')->all());
    }

    public function test_the_shared_prop_tells_the_shell_whether_native_push_is_available(): void
    {
        $this->actingAs($this->member())
            ->get(route('notifications.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('pwa.nativePushEnabled', true));
    }

    public function test_a_sender_reaches_a_browser_and_a_phone_through_the_one_contract(): void
    {
        $notifier = new FakePushNotifier;
        $this->app->instance(PushNotifier::class, $notifier);

        $member = $this->member();
        PushSubscription::factory()->for($member)->create();
        PushSubscription::factory()->for($member)->create(['platform' => 'android', 'public_key' => null, 'auth_token' => null]);

        $delivered = app(SendStoryHandoffPushAction::class)(
            StoryHandoff::factory()->create(),
            'https://app.test/handoff',
            false,
            collect([$member]),
        );

        $this->assertSame(2, $delivered);
    }
}
