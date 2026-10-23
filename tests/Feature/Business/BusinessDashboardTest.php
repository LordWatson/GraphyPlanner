<?php

namespace Tests\Feature\Business;

use App\Actions\Business\GetBusinessDashboardAction;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Step B-4.3 — the "Today" dashboard data: each section's SQL query against fixtures, with the
 * "today"/window boundaries resolved in the brand's timezone.
 */
class BusinessDashboardTest extends TestCase
{
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
}
