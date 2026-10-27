<?php

namespace Tests\Feature\Business;

use App\Actions\Push\SendNothingScheduledTomorrowPushAction;
use App\Actions\Push\SendPublishFailurePushAction;
use App\Contracts\PushNotifier;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PushSubscription;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakePushNotifier;
use Tests\TestCase;

/**
 * Step CG-1.3 — the two new web-push notifications switched on alongside real delivery: the
 * publish-failure push (business orgs only, beside the in-app bell) and the daily
 * "nothing scheduled tomorrow" nudge. Both run against the faked transport.
 */
class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private FakePushNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notifier = new FakePushNotifier;
        $this->app->instance(PushNotifier::class, $this->notifier);
    }

    /**
     * @return array{0: Organization, 1: Client, 2: User, 3: PushSubscription}
     */
    private function businessWorkspace(bool $withAccount = true): array
    {
        $org = Organization::factory()->business()->create([
            'default_timezone' => 'Europe/London',
            'onboarding_completed_at' => now(),
        ]);
        $brand = Client::factory()->for($org, 'organization')->primary()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $subscription = PushSubscription::factory()->create(['user_id' => $owner->id]);

        if ($withAccount) {
            SocialAccount::factory()->for($brand)->connected()->create([
                'org_id' => $org->id,
                'platform' => Platform::Instagram,
                'timezone' => 'Europe/London',
            ]);
        }

        return [$org, $brand, $owner, $subscription];
    }

    public function test_publish_failure_pushes_to_a_business_workspace(): void
    {
        [$org, $brand, , $subscription] = $this->businessWorkspace();
        $post = Post::factory()->for($brand)->create(['org_id' => $org->id, 'status' => PostStatus::Failed]);

        app(SendPublishFailurePushAction::class)($post);

        $this->assertCount(1, $this->notifier->sent);
        $this->assertSame($subscription->id, $this->notifier->sent[0]['subscription_id']);
    }

    public function test_publish_failure_push_skips_an_agency_org(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        PushSubscription::factory()->create(['user_id' => $owner->id]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Failed]);

        app(SendPublishFailurePushAction::class)($post);

        $this->assertSame([], $this->notifier->sent);
    }

    public function test_publish_failure_push_is_a_noop_when_unconfigured(): void
    {
        $this->notifier->configured = false;
        [$org, $brand] = $this->businessWorkspace();
        $post = Post::factory()->for($brand)->create(['org_id' => $org->id, 'status' => PostStatus::Failed]);

        app(SendPublishFailurePushAction::class)($post);

        $this->assertSame([], $this->notifier->sent);
    }

    public function test_a_gone_subscription_is_pruned(): void
    {
        [$org, $brand, , $subscription] = $this->businessWorkspace();
        $this->notifier->goneEndpoints = [$subscription->endpoint];
        $post = Post::factory()->for($brand)->create(['org_id' => $org->id, 'status' => PostStatus::Failed]);

        app(SendPublishFailurePushAction::class)($post);

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $subscription->id]);
    }

    public function test_nothing_scheduled_tomorrow_pushes_once_and_is_idempotent(): void
    {
        $this->businessWorkspace();
        $action = app(SendNothingScheduledTomorrowPushAction::class);

        $this->assertSame(1, $action());
        $this->assertCount(1, $this->notifier->sent);

        // A re-run the same local day is a no-op (cache guard).
        $this->assertSame(0, $action());
        $this->assertCount(1, $this->notifier->sent);
    }

    public function test_nothing_scheduled_tomorrow_skips_a_workspace_with_a_post_tomorrow(): void
    {
        [$org, $brand] = $this->businessWorkspace();
        $account = $brand->socialAccounts()->firstOrFail();
        $post = Post::factory()->for($brand)->create(['org_id' => $org->id, 'status' => PostStatus::Scheduled]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_at_utc' => Carbon::now('Europe/London')->addDay()->setTime(10, 0)->utc(),
            'status' => PostTargetStatus::Pending,
        ]);

        $this->assertSame(0, app(SendNothingScheduledTomorrowPushAction::class)());
        $this->assertSame([], $this->notifier->sent);
    }

    public function test_nothing_scheduled_tomorrow_skips_a_brand_without_an_account(): void
    {
        $this->businessWorkspace(withAccount: false);

        $this->assertSame(0, app(SendNothingScheduledTomorrowPushAction::class)());
        $this->assertSame([], $this->notifier->sent);
    }
}
