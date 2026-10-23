<?php

namespace Tests\Feature\Business;

use App\Actions\Business\GetBusinessInsightsAction;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Concerns\BuildsEngagementFixtures;
use Tests\TestCase;

/**
 * Step B-8.1 — the single-brand Insights page, built on the shared analytics core scoped to the
 * primary brand: consistency always, reach/engagement/top-posts/followers once metrics land.
 */
class BusinessInsightsTest extends TestCase
{
    use BuildsEngagementFixtures, RefreshDatabase;

    /**
     * @return array{0: Organization, 1: Client, 2: User}
     */
    private function businessBrand(string $timezone = 'Europe/London'): array
    {
        $org = Organization::factory()->business()->create(['default_timezone' => $timezone]);
        $brand = Client::factory()->for($org, 'organization')->primary()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        return [$org, $brand, $owner];
    }

    public function test_consistency_is_available_without_any_metrics(): void
    {
        [, $brand] = $this->businessBrand();
        $account = $this->engagementAccount($brand, Platform::Instagram, 'Europe/London');
        $this->publishedPost($brand, [$account], now()->subDays(2));

        $insights = app(GetBusinessInsightsAction::class)($brand, 'Europe/London');

        $this->assertFalse($insights['hasMetrics']);
        $this->assertSame(1, $insights['publishedTotal']);
        $this->assertSame(1, $insights['streak']);
        $this->assertCount(8, $insights['consistency']);
        $this->assertSame(0, $insights['engagement']['measured_channels']);
        $this->assertSame([], $insights['engagement']['top_posts']);
    }

    public function test_reach_engagement_and_best_times_appear_once_metrics_land(): void
    {
        [, $brand] = $this->businessBrand();
        $account = $this->engagementAccount($brand, Platform::Instagram, 'Europe/London');

        $post = $this->publishedPost($brand, [$account], now()->subDays(3));
        $this->reading($post, $account, ['impressions' => 1000, 'reach' => 800, 'likes' => 50, 'comments' => 10]);
        $this->followersOn($account, now()->subDays(10)->toDateString(), 1000);
        $this->followersOn($account, now()->subDay()->toDateString(), 1100);

        $insights = app(GetBusinessInsightsAction::class)($brand, 'Europe/London');

        $this->assertTrue($insights['hasMetrics']);
        $this->assertSame(1, $insights['engagement']['measured_channels']);
        $this->assertSame(800, $insights['engagement']['totals']['reach']);
        $this->assertSame(1100, $insights['engagement']['followers']['current']);
        $this->assertSame(100, $insights['engagement']['followers']['growth']);
        $this->assertNotEmpty($insights['engagement']['top_posts']);

        // Best times: one connected account, too little history to learn, so the platform default.
        $this->assertCount(1, $insights['bestTimes']);
        $this->assertSame('default', $insights['bestTimes'][0]['source']);
        $this->assertSame(Platform::Instagram->value, $insights['bestTimes'][0]['platform']);
        // The learned-only `best_times` the core attaches must be stripped from the business slice.
        $this->assertArrayNotHasKey('best_times', $insights['engagement']);
    }

    public function test_the_page_loads_for_a_business_owner(): void
    {
        [, , $owner] = $this->businessBrand();

        $this->actingAs($owner)
            ->get(route('business.insights.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('business/insights')->where('timezone', 'Europe/London'));
    }

    public function test_an_agency_cannot_reach_the_business_insights_route(): void
    {
        $agencyOwner = User::factory()
            ->for(Organization::factory()->create(), 'organization')
            ->role(Role::Owner)
            ->create();

        $this->actingAs($agencyOwner)
            ->get(route('business.insights.index'))
            ->assertNotFound();
    }
}
