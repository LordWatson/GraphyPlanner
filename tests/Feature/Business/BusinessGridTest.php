<?php

namespace Tests\Feature\Business;

use App\Enums\ApprovalMode;
use App\Enums\GridPlanStatus;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Client;
use App\Models\GridPlan;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsGridPlans;
use Tests\TestCase;

/**
 * Step CG-1.2 — the Instagram grid planner switched on for the business/creator shell. The brand is
 * implicit (`/grid`); the planner/production/scheduling reuse the shared brand-agnostic `grids.*`
 * surface. Grid-produced posts inherit the primary brand's `internal_only` approval (no client
 * hand-off). The agency `/grids` hub is now out of reach for a business org.
 */
class BusinessGridTest extends TestCase
{
    use BuildsGridPlans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->business()->create(['default_timezone' => 'Europe/London']);
        $this->client = Client::factory()->for($this->org, 'organization')->primary()->create([
            'default_approval_mode' => ApprovalMode::InternalOnly,
        ]);
        $this->instagram = SocialAccount::factory()->for($this->client)->connected()->create([
            'org_id' => $this->org->id,
            'platform' => Platform::Instagram,
            'handle' => '@creator',
            'timezone' => 'Europe/London',
        ]);
        $this->staff[Role::Owner->value] = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    public function test_a_business_owner_creates_a_grid_plan(): void
    {
        $this->actingAs($this->owner())
            ->post(route('business.grids.store'), [
                'name' => 'November launch',
                'social_account_id' => $this->instagram->id,
                'pinned_count' => 0,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('grid_plans', [
            'org_id' => $this->org->id,
            'client_id' => $this->client->id,
            'name' => 'November launch',
        ]);
    }

    public function test_the_grid_hub_lists_the_brands_plans(): void
    {
        $this->planWithTiles(3);

        $this->actingAs($this->owner())
            ->get(route('business.grids.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('business/grids/index')
                ->where('hasInstagram', true)
                ->has('grids.data', 1));
    }

    public function test_the_hub_explains_itself_without_an_instagram_account(): void
    {
        $this->instagram->delete();

        $this->actingAs($this->owner())
            ->get(route('business.grids.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('hasInstagram', false));
    }

    public function test_a_business_owner_produces_posts_and_schedules_the_sequence(): void
    {
        $plan = $this->planWithTiles(3, GridPlanStatus::Approved);

        // Turn the approved tiles into real posts.
        $this->actingAs($this->owner())
            ->post(route('grids.posts.store', $plan))
            ->assertRedirect();

        $posts = Post::query()->where('client_id', $this->client->id)->get();
        $this->assertCount(3, $posts);
        // Grid-produced posts inherit the primary brand's internal-only approval (no client review).
        $this->assertTrue($posts->every(fn (Post $post) => $post->approval_mode === ApprovalMode::InternalOnly));

        // Schedule the sequence (daily from tomorrow).
        $this->actingAs($this->owner())
            ->post(route('grids.schedule', $plan), [
                'start_date' => now('Europe/London')->addDay()->toDateString(),
                'time' => '09:00',
                'cadence' => 'daily',
            ])
            ->assertRedirect();

        $this->assertNotNull($plan->tiles()->orderBy('position')->first()?->planned_local_date);
    }

    public function test_another_orgs_grid_is_not_reachable(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherClient = Client::factory()->for($otherOrg, 'organization')->create();
        $otherAccount = SocialAccount::factory()->for($otherClient)->connected()->create([
            'org_id' => $otherOrg->id,
            'platform' => Platform::Instagram,
        ]);
        $foreign = GridPlan::factory()->create([
            'org_id' => $otherOrg->id,
            'client_id' => $otherClient->id,
            'social_account_id' => $otherAccount->id,
            'created_by' => User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create()->id,
        ]);

        // The grid policy refuses a bound plan from another org (tenant isolation).
        $this->actingAs($this->owner())
            ->get(route('grids.edit', $foreign))
            ->assertForbidden();
    }

    public function test_the_agency_grids_hub_redirects_a_business_org(): void
    {
        $this->actingAs($this->owner())
            ->get(route('grids.index'))
            ->assertRedirect(route('business.grids.index'));
    }
}
