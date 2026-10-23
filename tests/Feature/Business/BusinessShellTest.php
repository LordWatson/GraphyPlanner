<?php

namespace Tests\Feature\Business;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Step B-4.1 / B-4.2 — the business workspace shell: its brand-implicit routes resolve for a
 * business Owner, agency orgs can't reach them, and the agency surfaces a business org would
 * otherwise hit are redirected to the business equivalent or 404.
 */
class BusinessShellTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: User, 2: Client}
     */
    private function business(): array
    {
        $organization = Organization::factory()->business()->create();
        $brand = Client::factory()->for($organization, 'organization')->primary()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        return [$organization, $owner, $brand];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function businessGetRoutes(): array
    {
        return [
            'today' => ['business.today'],
            'posts index' => ['business.posts.index'],
            'ideas' => ['business.ideas.index'],
            'media' => ['business.media.index'],
            'collections' => ['business.collections.index'],
            'brand' => ['business.brand.edit'],
            'accounts' => ['business.accounts.index'],
            'insights' => ['business.insights.index'],
        ];
    }

    #[DataProvider('businessGetRoutes')]
    public function test_business_owner_can_reach_each_shell_page(string $routeName): void
    {
        [, $owner] = $this->business();

        $this->actingAs($owner)->get(route($routeName))->assertOk();
    }

    #[DataProvider('businessGetRoutes')]
    public function test_an_agency_user_cannot_reach_the_business_shell(string $routeName): void
    {
        $organization = Organization::factory()->create(); // agency by default
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->get(route($routeName))->assertNotFound();
    }

    public function test_today_dashboard_renders_its_sections(): void
    {
        [, $owner] = $this->business();

        $this->actingAs($owner)
            ->get(route('business.today'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('business/today')
                ->has('dashboard.upNext')
                ->has('dashboard.needsYou')
                ->has('dashboard.gaps')
                ->has('dashboard.thisWeek'));
    }

    public function test_posts_index_is_scoped_to_the_primary_brand(): void
    {
        [$organization, $owner, $brand] = $this->business();

        $mine = Post::factory()->for($brand)->create([
            'org_id' => $organization->id,
            'status' => PostStatus::Draft,
            'master_caption' => 'My own post',
        ]);

        // A post in another org must never appear.
        $otherOrg = Organization::factory()->business()->create();
        $otherBrand = Client::factory()->for($otherOrg, 'organization')->primary()->create();
        Post::factory()->for($otherBrand)->create([
            'org_id' => $otherOrg->id,
            'master_caption' => 'Someone else',
        ]);

        $this->actingAs($owner)
            ->get(route('business.posts.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('business/posts/index')
                ->has('posts.data', 1)
                ->where('posts.data.0.id', $mine->id));
    }

    public function test_create_opens_a_fresh_draft_in_the_editor(): void
    {
        [$organization, $owner, $brand] = $this->business();

        $response = $this->actingAs($owner)->post(route('business.posts.create'));

        $post = Post::query()->where('client_id', $brand->id)->sole();
        $this->assertSame(PostStatus::Draft, $post->status);
        $response->assertRedirect(route('posts.edit', $post));
    }

    public function test_media_library_returns_the_brands_assets_as_json(): void
    {
        [, $owner] = $this->business();

        $this->actingAs($owner)
            ->getJson(route('business.media.library'))
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json->has('data')->has('meta'));
    }

    public function test_a_collection_can_be_created_for_the_brand(): void
    {
        [$organization, $owner, $brand] = $this->business();

        $this->actingAs($owner)
            ->post(route('business.collections.store'), ['name' => 'Spring launch'])
            ->assertRedirect(route('business.collections.index'));

        $this->assertDatabaseHas('campaigns', [
            'client_id' => $brand->id,
            'name' => 'Spring launch',
        ]);
    }

    public function test_an_account_can_be_added_under_the_primary_brand(): void
    {
        [$organization, $owner, $brand] = $this->business();

        $this->actingAs($owner)
            ->post(route('business.accounts.store'), [
                'platform' => Platform::Instagram->value,
                'handle' => 'mybakery',
                'timezone' => 'Europe/London',
            ])
            ->assertRedirect(route('business.accounts.index'));

        $this->assertDatabaseHas('social_accounts', [
            'client_id' => $brand->id,
            'handle' => 'mybakery',
        ]);
    }

    public function test_brand_basics_and_posting_goal_save(): void
    {
        [, $owner, $brand] = $this->business();

        $this->actingAs($owner)
            ->put(route('business.brand.profile.update'), [
                'name' => 'My Bakery',
                'one_liner' => 'Sourdough every morning',
                'location' => 'Bristol',
                'posting_goal' => [
                    'posts_per_week' => 3,
                    'preferred_days' => [1, 3, 5],
                ],
            ])
            ->assertRedirect(route('business.brand.edit'));

        $brand->refresh();
        $this->assertSame('My Bakery', $brand->name);
        $this->assertSame('Sourdough every morning', $brand->industry);
        $this->assertSame(3, $brand->postingGoal()->postsPerWeek);
        $this->assertSame([1, 3, 5], $brand->postingGoal()->preferredDays);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function redirectedAgencyRoutes(): array
    {
        return [
            'dashboard → today' => ['dashboard', 'business.today'],
            'needs-attention → today' => ['needs-attention', 'business.today'],
            'campaigns hub → collections' => ['campaigns.index', 'business.collections.index'],
        ];
    }

    #[DataProvider('redirectedAgencyRoutes')]
    public function test_business_org_is_redirected_off_agency_landing_pages(string $from, string $to): void
    {
        [, $owner] = $this->business();

        $this->actingAs($owner)
            ->get(route($from))
            ->assertRedirect(route($to));
    }

    public function test_business_org_is_redirected_from_a_client_tab_to_its_shell(): void
    {
        [, $owner, $brand] = $this->business();

        $this->actingAs($owner)
            ->get(route('clients.posts.index', $brand))
            ->assertRedirect(route('business.posts.index'));
    }

    public function test_agency_behaviour_is_unchanged_dashboard_still_loads(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        // And the business shell is closed to them.
        $this->actingAs($owner)->get(route('business.today'))->assertNotFound();
    }
}
