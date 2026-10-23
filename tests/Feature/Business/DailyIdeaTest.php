<?php

namespace Tests\Feature\Business;

use App\Actions\AI\GenerateDailyIdeaAction;
use App\Actions\Workspace\CreatePrimaryBrandAction;
use App\Contracts\LocalIdeaProvider;
use App\Enums\OrganizationType;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Support\AI\AiPostIdea;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\IdeaContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DailyIdeaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: Client, 2: User}
     */
    private function businessWorkspace(string $timezone = 'UTC'): array
    {
        $organization = Organization::factory()->business()->create(['default_timezone' => $timezone]);
        $brand = (new CreatePrimaryBrandAction)($organization);
        $owner = User::factory()->create([
            'org_id' => $organization->id,
            'role' => Role::Owner,
            'email_verified_at' => now(),
        ]);

        return [$organization, $brand, $owner];
    }

    /**
     * A fake local-idea provider that counts calls, captures the grounding context, and returns a
     * fixed idea — so tests can prove the engine is wired without a real vendor.
     */
    private function fakeProvider(string $title = 'Pancake Day crêpe reel'): object
    {
        return new class($title) implements LocalIdeaProvider
        {
            public int $calls = 0;

            public ?IdeaContext $lastContext = null;

            public function __construct(private readonly string $title) {}

            public function recommendLocalPosts(Client $client, IdeaContext $context): AiPostIdeas
            {
                $this->calls++;
                $this->lastContext = $context;

                return new AiPostIdeas([
                    new AiPostIdea(
                        title: $this->title,
                        description: 'A behind-the-counter reel for Pancake Day.',
                        platforms: ['instagram'],
                        suggestedCaption: 'Flipping good. 🥞',
                    ),
                ]);
            }
        };
    }

    public function test_it_generates_and_caches_one_idea_per_local_day(): void
    {
        [, $brand] = $this->businessWorkspace();
        $provider = $this->fakeProvider();
        $this->app->instance(LocalIdeaProvider::class, $provider);

        $action = app(GenerateDailyIdeaAction::class);

        $first = $action($brand, 'UTC');
        $this->assertNotNull($first);
        $this->assertSame('Pancake Day crêpe reel', $first['title']);
        $this->assertNotNull($first['ai_post_idea_id']);

        // A second call the same day is a cache hit — no second vendor call.
        $second = $action($brand, 'UTC');
        $this->assertSame($first, $second);
        $this->assertSame(1, $provider->calls);

        // The idea was persisted (so "Use this idea" can turn it into a draft), and usage recorded.
        $this->assertDatabaseHas('ai_post_ideas', ['client_id' => $brand->id, 'title' => 'Pancake Day crêpe reel']);
        $this->assertDatabaseHas('ai_usage_logs', ['client_id' => $brand->id, 'method' => 'recommendLocalPosts']);
    }

    public function test_force_regenerates_even_with_a_cached_idea(): void
    {
        [, $brand] = $this->businessWorkspace();
        $provider = $this->fakeProvider();
        $this->app->instance(LocalIdeaProvider::class, $provider);
        $action = app(GenerateDailyIdeaAction::class);

        $action($brand, 'UTC');
        $action($brand, 'UTC', force: true);

        $this->assertSame(2, $provider->calls);
    }

    public function test_the_context_is_grounded_in_the_brands_country(): void
    {
        [, $brand] = $this->businessWorkspace();
        $brand->update(['countries' => ['GB']]);
        $provider = $this->fakeProvider();
        $this->app->instance(LocalIdeaProvider::class, $provider);

        app(GenerateDailyIdeaAction::class)($brand->refresh(), 'UTC');

        $this->assertSame('GB', $provider->lastContext?->country);
    }

    public function test_the_command_fills_ideas_at_the_brands_local_hour_and_is_idempotent(): void
    {
        config(['ai.enabled' => true]);
        [, $brand] = $this->businessWorkspace('UTC');
        config(['ai.ideas.daily_hour' => (int) now('UTC')->hour]);

        $provider = $this->fakeProvider();
        $this->app->instance(LocalIdeaProvider::class, $provider);

        $this->artisan('business:generate-daily-ideas')->assertSuccessful();
        $this->artisan('business:generate-daily-ideas')->assertSuccessful();

        $this->assertSame(1, $provider->calls, 'the cached day is reused on the second run');
        $this->assertNotNull(app(GenerateDailyIdeaAction::class)->cached($brand, 'UTC'));
    }

    public function test_the_command_skips_orgs_outside_the_target_hour(): void
    {
        config(['ai.enabled' => true]);
        $this->businessWorkspace('UTC');
        config(['ai.ideas.daily_hour' => ((int) now('UTC')->hour + 2) % 24]);

        $provider = $this->fakeProvider();
        $this->app->instance(LocalIdeaProvider::class, $provider);

        $this->artisan('business:generate-daily-ideas')->assertSuccessful();

        $this->assertSame(0, $provider->calls);
    }

    public function test_the_command_no_ops_when_ai_is_disabled(): void
    {
        config(['ai.enabled' => false]);
        $this->businessWorkspace('UTC');
        config(['ai.ideas.daily_hour' => (int) now('UTC')->hour]);

        $provider = $this->fakeProvider();
        $this->app->instance(LocalIdeaProvider::class, $provider);

        $this->artisan('business:generate-daily-ideas')->assertSuccessful();

        $this->assertSame(0, $provider->calls);
    }

    public function test_the_command_skips_suspended_and_agency_orgs(): void
    {
        config(['ai.enabled' => true, 'ai.ideas.daily_hour' => (int) now('UTC')->hour]);

        // Suspended business org.
        $suspended = Organization::factory()->business()->create(['default_timezone' => 'UTC', 'suspended_at' => now()]);
        (new CreatePrimaryBrandAction)($suspended);

        // An agency org has no primary brand and is a different type.
        Organization::factory()->create(['type' => OrganizationType::Agency, 'default_timezone' => 'UTC']);

        $provider = $this->fakeProvider();
        $this->app->instance(LocalIdeaProvider::class, $provider);

        $this->artisan('business:generate-daily-ideas')->assertSuccessful();

        $this->assertSame(0, $provider->calls);
    }

    public function test_the_today_dashboard_exposes_the_cached_idea(): void
    {
        [, $brand, $owner] = $this->businessWorkspace('UTC');
        $this->app->instance(LocalIdeaProvider::class, $this->fakeProvider());
        app(GenerateDailyIdeaAction::class)($brand, 'UTC');

        $this->actingAs($owner)
            ->get('/today')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('business/today')
                ->where('dashboard.ideaOfTheDay.title', 'Pancake Day crêpe reel'));
    }

    public function test_the_today_dashboard_idea_is_null_without_a_cached_one(): void
    {
        [, , $owner] = $this->businessWorkspace('UTC');

        $this->actingAs($owner)
            ->get('/today')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('dashboard.ideaOfTheDay', null));
    }

    public function test_more_ideas_endpoint_generates_grounded_ideas_and_records_usage(): void
    {
        config(['ai.enabled' => true]);
        [, $brand, $owner] = $this->businessWorkspace('UTC');
        $brand->update(['countries' => ['GB']]);
        $provider = $this->fakeProvider('A fresh idea');
        $this->app->instance(LocalIdeaProvider::class, $provider);

        $this->actingAs($owner)
            ->postJson(route('business.ideas.generate'))
            ->assertOk()
            ->assertJsonPath('ideas.0.title', 'A fresh idea');

        $this->assertSame('GB', $provider->lastContext?->country);
        $this->assertDatabaseHas('ai_post_ideas', ['client_id' => $brand->id, 'title' => 'A fresh idea']);
        $this->assertDatabaseHas('ai_usage_logs', ['client_id' => $brand->id, 'method' => 'recommendLocalPosts']);
    }

    public function test_more_ideas_endpoint_is_404_when_ai_is_disabled(): void
    {
        config(['ai.enabled' => false]);
        [, , $owner] = $this->businessWorkspace('UTC');

        $this->actingAs($owner)->postJson(route('business.ideas.generate'))->assertNotFound();
    }
}
