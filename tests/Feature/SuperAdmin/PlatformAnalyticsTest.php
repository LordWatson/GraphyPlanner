<?php

namespace Tests\Feature\SuperAdmin;

use App\Actions\SuperAdmin\GetPlatformAnalyticsSummaryAction;
use App\Enums\ClientStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\User;
use App\Support\SuperAdmin\AnalyticsFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Step 15.4 — platform-wide totals and the per-org drill-down over fixtures in several orgs.
 */
class PlatformAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Organization $northlight;

    private Organization $harbour;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-17 12:00:00');

        $this->superAdmin = User::factory()->superAdmin()->create();

        // Northlight: new this month, two staff, two clients (one paused), busy publisher.
        $this->northlight = Organization::factory()->create(['name' => 'Northlight Studio']);
        User::factory()->for($this->northlight, 'organization')->role(Role::Owner)->create();
        User::factory()->for($this->northlight, 'organization')->role(Role::Strategist)->create();
        $northlightClient = Client::factory()->for($this->northlight, 'organization')->create();
        Client::factory()->for($this->northlight, 'organization')->create(['status' => ClientStatus::Paused]);

        $this->transitions($northlightClient, PostStatus::Published, [1, 2, 3]);
        $this->transitions($northlightClient, PostStatus::Failed, [4]);
        $this->transitions($northlightClient, PostStatus::Published, [60]); // outside the 30-day window

        $this->invoice($northlightClient, InvoiceStatus::Sent, 1000, 'USD', 10);
        $this->invoice($northlightClient, InvoiceStatus::Paid, 500, 'USD', 5, paidDaysAgo: 2);
        $this->invoice($northlightClient, InvoiceStatus::Sent, 300, 'EUR', 5);
        $this->invoice($northlightClient, InvoiceStatus::Draft, 999, 'USD', 5); // never counted
        $this->invoice($northlightClient, InvoiceStatus::Void, 777, 'USD', 5); // never counted

        // Harbour: signed up long ago, one owner, one client.
        $this->harbour = Organization::factory()->create(['name' => 'Harbour & Co', 'created_at' => now()->subDays(100)]);
        User::factory()->for($this->harbour, 'organization')->role(Role::Owner)->create();
        $harbourClient = Client::factory()->for($this->harbour, 'organization')->create();
        $this->transitions($harbourClient, PostStatus::Published, [6]);
        $this->invoice($harbourClient, InvoiceStatus::Paid, 200, 'USD', 3, paidDaysAgo: 1);

        // A suspended, empty org.
        Organization::factory()->suspended()->create();

        // The preview fixture — demo data that must never reach platform totals.
        $preview = Organization::factory()->create(['slug' => Organization::PREVIEW_SLUG]);
        $previewClient = Client::factory()->for($preview, 'organization')->create();
        $this->transitions($previewClient, PostStatus::Published, [1, 1, 1]);
        $this->invoice($previewClient, InvoiceStatus::Paid, 50000, 'USD', 1, paidDaysAgo: 1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  list<int>  $daysAgo
     */
    private function transitions(Client $client, PostStatus $status, array $daysAgo): void
    {
        foreach ($daysAgo as $days) {
            $post = Post::factory()->for($client)->create(['status' => $status]);

            PostActivityLog::factory()->create([
                'post_id' => $post->id,
                'user_id' => null,
                'from_status' => PostStatus::Publishing,
                'to_status' => $status,
                'created_at' => now()->subDays($days),
            ]);
        }
    }

    private function invoice(Client $client, InvoiceStatus $status, float $amount, string $currency, int $issuedDaysAgo, ?int $paidDaysAgo = null): void
    {
        Invoice::factory()->for($client)->create([
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'issue_date' => now()->subDays($issuedDaysAgo)->toDateString(),
            'paid_at' => $paidDaysAgo === null ? null : now()->subDays($paidDaysAgo),
        ]);
    }

    public function test_the_analytics_page_shows_correct_platform_wide_totals(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.analytics.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('super-admin/analytics/index')
                ->where('summary.filters.range', '30d')
                ->where('summary.filters.from', '2026-09-18')
                ->where('summary.filters.to', '2026-10-17')
                ->where('summary.filters.granularity', 'day')
                // Preview fixture excluded; Harbour signed up before the window.
                ->where('summary.organizations', ['total' => 3, 'active' => 2, 'suspended' => 1, 'new' => 2])
                ->where('summary.users.total', 3)
                ->where('summary.clients', ['total' => 3, 'active' => 2, 'new' => 3])
                ->where('summary.posts.published', 4)
                ->where('summary.posts.failed', 1)
                ->where('summary.posts.success_rate', 80)
                ->where('summary.posts.published_all_time', 5)
                ->where('summary.revenue.invoiced', [
                    ['currency' => 'USD', 'total' => 1700],
                    ['currency' => 'EUR', 'total' => 300],
                ])
                ->where('summary.revenue.collected', [['currency' => 'USD', 'total' => 700]])
                ->where('summary.revenue.outstanding', [
                    ['currency' => 'USD', 'total' => 1000],
                    ['currency' => 'EUR', 'total' => 300],
                ])
                ->where('summary.primary_currency', 'USD')
                ->has('summary.series', 30)
                ->where('summary.top_organizations.0.name', 'Northlight Studio')
                ->where('summary.top_organizations.0.published', 3)
                ->where('summary.top_organizations.0.invoiced', 1500)
                ->where('summary.engagement', null)
                ->has('organizationOptions', 4));
    }

    public function test_the_series_buckets_add_up_to_the_totals(): void
    {
        $summary = app(GetPlatformAnalyticsSummaryAction::class)(AnalyticsFilters::fromArray(['range' => '30d']));

        $this->assertSame(4, array_sum(array_column($summary['series'], 'published')));
        $this->assertSame(1, array_sum(array_column($summary['series'], 'failed')));
        $this->assertSame(2, array_sum(array_column($summary['series'], 'signups')));
        $this->assertEqualsWithDelta(1700.0, array_sum(array_column($summary['series'], 'invoiced')), 0.001);
    }

    public function test_the_per_org_drill_down_is_scoped_to_one_organization(): void
    {
        // Step 17.9: the drill-down runs the organization's own analytics, every section visible.
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.organizations.analytics', $this->northlight))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('super-admin/organizations/analytics')
                ->where('organization.id', $this->northlight->id)
                ->where('summary.scope', 'organization')
                ->where('summary.organization.name', 'Northlight Studio')
                ->where('summary.metrics', ['clients', 'posts', 'approvals', 'team', 'revenue', 'ai', 'engagement'])
                ->where('summary.clients.total', 2)
                ->where('summary.clients.active', 1)
                ->where('summary.clients.paused', 1)
                ->where('summary.team.members', fn ($members) => count($members) === 2)
                ->where('summary.posts.published', 3)
                ->where('summary.posts.failed', 1)
                ->where('summary.revenue.invoiced', [
                    ['currency' => 'USD', 'total' => 1500],
                    ['currency' => 'EUR', 'total' => 300],
                ])
                ->whereNot('summary.ai.cost_estimate', null)
                ->has('summary.top_clients', 2));
    }

    public function test_a_custom_range_and_org_filter_narrow_the_figures(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.analytics.index', [
                'range' => 'custom',
                'from' => '2026-08-01',
                'to' => '2026-08-31',
                'org_ids' => [$this->northlight->id],
            ]))
            ->assertInertia(fn ($page) => $page
                ->where('summary.filters.range', 'custom')
                ->where('summary.posts.published', 1)
                ->where('summary.posts.failed', 0)
                ->where('summary.revenue.invoiced', []));
    }

    public function test_long_ranges_are_bucketed_by_month(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.analytics.index', ['range' => '12m']))
            ->assertInertia(fn ($page) => $page
                ->where('summary.filters.granularity', 'month')
                ->has('summary.series', 12)
                ->where('summary.series.11.key', '2026-10'));
    }

    public function test_only_the_selected_metric_sections_are_computed(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.analytics.index', ['metrics' => ['posts']]))
            ->assertInertia(fn ($page) => $page
                ->where('summary.metrics', ['posts'])
                ->where('summary.posts.published', 4)
                ->where('summary.revenue', null)
                ->where('summary.organizations', null)
                ->where('summary.ai', null));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.analytics.index', ['range' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-01']))
            ->assertSessionHasErrors('to');

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.analytics.index', ['metrics' => ['nonsense']]))
            ->assertSessionHasErrors('metrics.0');

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.analytics.index', ['org_ids' => [999999]]))
            ->assertSessionHasErrors('org_ids.0');
    }

    public function test_the_overview_uses_the_last_30_days_and_lists_signups_and_suspensions(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('super-admin/dashboard')
                ->where('summary.filters.range', '30d')
                ->where('summary.posts.published', 4)
                ->has('recentOrganizations', 3)
                ->has('suspendedOrganizations', 1)
                ->has('reports', 0));
    }
}
