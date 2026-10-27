<?php

namespace Tests\Feature\Business;

use App\Actions\Business\GetBusinessDashboardAction;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\GridPlan;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Grids\GridOrderRiskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Inbox\Concerns\BuildsInboxFixtures;
use Tests\TestCase;

/**
 * Step B-4.3 — the "Today" dashboard data: each section's SQL query against fixtures, with the
 * "today"/window boundaries resolved in the brand's timezone.
 */
class BusinessDashboardTest extends TestCase
{
    use BuildsInboxFixtures;
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: User, 2: Client, 3: SocialAccount}
     */
    private function business(array $brandAttributes = [], string $timezone = 'Europe/London'): array
    {
        $organization = Organization::factory()->business()->create(['default_timezone' => $timezone]);
        $brand = Client::factory()->for($organization, 'organization')->primary()->create($brandAttributes);
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();
        $account = SocialAccount::factory()->for($brand)->connected()->create([
            'org_id' => $organization->id,
            'platform' => Platform::Instagram,
            'timezone' => $timezone,
        ]);

        return [$organization, $owner, $brand, $account];
    }

    private function scheduledPost(Client $brand, SocialAccount $account, Carbon $when): Post
    {
        $post = Post::factory()->for($brand)->create([
            'org_id' => $brand->org_id,
            'status' => PostStatus::Scheduled,
        ]);

        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_at_utc' => $when,
            'status' => PostTargetStatus::Pending,
        ]);

        return $post;
    }

    public function test_setup_checklist_is_incomplete_for_a_brand_new_brand(): void
    {
        // UX overhaul (business propagation): a fresh brand has every setup step outstanding.
        $organization = Organization::factory()->business()->create();
        $brand = Client::factory()->for($organization, 'organization')->primary()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        $data = app(GetBusinessDashboardAction::class)($owner, $brand, 'UTC');

        $this->assertSame([
            'accounts' => false,
            'brand' => false,
            'goal' => false,
            'post' => false,
            'complete' => false,
        ], $data['setup']);
    }

    public function test_setup_checklist_completes_once_the_brand_is_set_up(): void
    {
        // The `business()` helper already connects an Instagram account.
        [$organization, $owner, $brand] = $this->business();
        $brand->brandBrain()->create([
            'voice' => ['tone' => 'Warm', 'personality' => null, 'do_nots' => []],
        ]);
        $brand->update(['posting_goal' => ['posts_per_week' => 3, 'preferred_days' => [1, 3, 5]]]);
        Post::factory()->for($brand)->create([
            'org_id' => $organization->id,
            'status' => PostStatus::Draft,
        ]);

        $data = app(GetBusinessDashboardAction::class)($owner, $brand->fresh(), 'UTC');

        $this->assertSame([
            'accounts' => true,
            'brand' => true,
            'goal' => true,
            'post' => true,
            'complete' => true,
        ], $data['setup']);
    }

    public function test_up_next_lists_scheduled_targets_soonest_first(): void
    {
        [$organization, $owner, $brand, $account] = $this->business();

        $later = $this->scheduledPost($brand, $account, Carbon::now()->addDays(3));
        $sooner = $this->scheduledPost($brand, $account, Carbon::now()->addHours(2));

        $data = app(GetBusinessDashboardAction::class)($owner, $brand, 'Europe/London');

        $this->assertCount(2, $data['upNext']);
        $this->assertSame($sooner->id, $data['upNext'][0]['post_id']);
        $this->assertSame($later->id, $data['upNext'][1]['post_id']);
    }

    public function test_gaps_reflect_the_posting_goal(): void
    {
        [$organization, $owner, $brand] = $this->business([
            'posting_goal' => ['posts_per_week' => null, 'preferred_days' => [1, 2, 3, 4, 5, 6, 7]],
        ]);

        $data = app(GetBusinessDashboardAction::class)($owner, $brand, 'Europe/London');

        // A daily goal with nothing scheduled → the whole 14-day window is open.
        $this->assertTrue($data['gaps']['hasGoal']);
        $this->assertSame(14, $data['gaps']['count']);
    }

    public function test_no_goal_means_no_gaps(): void
    {
        [, $owner, $brand] = $this->business();

        $data = app(GetBusinessDashboardAction::class)($owner, $brand, 'Europe/London');

        $this->assertFalse($data['gaps']['hasGoal']);
        $this->assertSame(0, $data['gaps']['count']);
    }

    public function test_a_scheduled_day_is_not_a_gap(): void
    {
        [$organization, $owner, $brand, $account] = $this->business([
            'posting_goal' => ['posts_per_week' => null, 'preferred_days' => [1, 2, 3, 4, 5, 6, 7]],
        ]);

        // Schedule something tomorrow (brand time) → tomorrow drops out of the gaps.
        $tomorrow = Carbon::now('Europe/London')->addDay();
        $this->scheduledPost($brand, $account, $tomorrow->clone()->setTime(9, 0)->utc());

        $data = app(GetBusinessDashboardAction::class)($owner, $brand, 'Europe/London');

        $gapDates = array_column($data['gaps']['days'], 'date');
        $this->assertNotContains($tomorrow->toDateString(), $gapDates);
        $this->assertSame(13, $data['gaps']['count']);
    }

    public function test_needs_you_surfaces_failed_posts_disconnected_accounts_and_stale_drafts(): void
    {
        [$organization, $owner, $brand, $account] = $this->business();

        Post::factory()->for($brand)->create([
            'org_id' => $organization->id,
            'status' => PostStatus::Failed,
            'master_caption' => 'This one failed',
        ]);

        Post::factory()->for($brand)->create([
            'org_id' => $organization->id,
            'status' => PostStatus::Draft,
            'updated_at' => Carbon::now()->subDays(10),
        ]);

        SocialAccount::factory()->for($brand)->create([
            'org_id' => $organization->id,
            'platform' => Platform::Facebook,
            'connection_status' => ConnectionStatus::TokenExpired,
        ]);

        $data = app(GetBusinessDashboardAction::class)($owner, $brand, 'Europe/London');

        $types = array_column($data['needsYou']['items'], 'type');
        $this->assertContains('failed', $types);
        $this->assertContains('disconnected', $types);
        $this->assertContains('stale_draft', $types);
        $this->assertGreaterThanOrEqual(3, $data['needsYou']['total']);
    }

    public function test_this_week_counts_published_and_scheduled(): void
    {
        [$organization, $owner, $brand, $account] = $this->business();

        // Published yesterday.
        $published = Post::factory()->for($brand)->create([
            'org_id' => $organization->id,
            'status' => PostStatus::Published,
        ]);
        $published->targets()->create([
            'social_account_id' => $account->id,
            'status' => PostTargetStatus::Published,
            'published_at' => Carbon::now()->subDay(),
        ]);

        // Scheduled in three days.
        $this->scheduledPost($brand, $account, Carbon::now()->addDays(3));

        $data = app(GetBusinessDashboardAction::class)($owner, $brand, 'Europe/London');

        $this->assertSame(1, $data['thisWeek']['publishedLast7']);
        $this->assertSame(1, $data['thisWeek']['scheduledNext7']);
        $this->assertFalse($data['thisWeek']['hasMetrics']);
    }

    public function test_dashboard_only_sees_its_own_brand(): void
    {
        [$organization, $owner, $brand, $account] = $this->business();
        $this->scheduledPost($brand, $account, Carbon::now()->addHours(2));

        // A different business with its own scheduled post.
        $otherOrg = Organization::factory()->business()->create();
        $otherBrand = Client::factory()->for($otherOrg, 'organization')->primary()->create();
        $otherAccount = SocialAccount::factory()->for($otherBrand)->connected()->create([
            'org_id' => $otherOrg->id,
            'platform' => Platform::Instagram,
        ]);
        $this->scheduledPost($otherBrand, $otherAccount, Carbon::now()->addHour());

        $data = app(GetBusinessDashboardAction::class)($owner, $brand, 'Europe/London');

        $this->assertCount(1, $data['upNext']);
    }

    public function test_needs_you_surfaces_unanswered_comments_for_the_primary_brand_only(): void
    {
        // Step CG-1.1: an open comment awaiting a reply on the brand's post shows on Today.
        [$org, $owner, $brand] = $this->business();
        $this->conversation($org, $brand, ['is_reply_needed' => true]);

        // A comment on a *different* org's brand must never leak in.
        $otherOrg = $this->inboxOrg();
        $this->conversation($otherOrg, null, ['is_reply_needed' => true]);

        $data = app(GetBusinessDashboardAction::class)($owner, $brand, 'Europe/London');

        $comments = array_values(array_filter(
            $data['needsYou']['items'],
            fn (array $item) => $item['type'] === 'comment',
        ));

        $this->assertCount(1, $comments);
        $this->assertArrayHasKey('conversation_id', $comments[0]);
    }

    public function test_needs_you_surfaces_grid_plans_at_risk_for_the_primary_brand_only(): void
    {
        // Step CG-1.4: a grid whose order has drifted shows on Today. Risk detection itself is
        // covered by the grid suite; here we double it so the dashboard surfacing is isolated.
        [$org, $owner, $brand, $account] = $this->business();

        $risk = \Mockery::mock(GridOrderRiskService::class);
        $risk->shouldReceive('atRisk')->andReturn(['reason' => 'Posts may go out of order.', 'later_post_ids' => [1, 2]]);
        $this->app->instance(GridOrderRiskService::class, $risk);

        GridPlan::factory()->create([
            'org_id' => $org->id,
            'client_id' => $brand->id,
            'social_account_id' => $account->id,
            'created_by' => $owner->id,
            'order_risk_notified_at' => now(),
        ]);

        // A flagged grid in another org must never leak in.
        $otherOrg = Organization::factory()->business()->create();
        $otherBrand = Client::factory()->for($otherOrg, 'organization')->primary()->create();
        $otherAccount = SocialAccount::factory()->for($otherBrand)->connected()->create([
            'org_id' => $otherOrg->id,
            'platform' => Platform::Instagram,
        ]);
        GridPlan::factory()->create([
            'org_id' => $otherOrg->id,
            'client_id' => $otherBrand->id,
            'social_account_id' => $otherAccount->id,
            'created_by' => User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create()->id,
            'order_risk_notified_at' => now(),
        ]);

        $data = app(GetBusinessDashboardAction::class)($owner, $brand, 'Europe/London');

        $gridRows = array_values(array_filter(
            $data['needsYou']['items'],
            fn (array $item) => $item['type'] === 'grid_risk',
        ));

        $this->assertCount(1, $gridRows);
        $this->assertArrayHasKey('grid_plan_id', $gridRows[0]);
    }
}
