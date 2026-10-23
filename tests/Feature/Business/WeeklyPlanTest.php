<?php

namespace Tests\Feature\Business;

use App\Actions\AI\GenerateWeeklyPlanAction;
use App\Actions\Workspace\CreatePrimaryBrandAction;
use App\Contracts\LocalIdeaProvider;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\AiPostIdea;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Support\AI\AiPostIdea as AiPostIdeaDto;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\IdeaContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeeklyPlanTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: Client, 2: User}
     */
    private function businessWorkspace(string $timezone = 'UTC'): array
    {
        $organization = Organization::factory()->business()->create(['default_timezone' => $timezone]);
        $brand = (new CreatePrimaryBrandAction)($organization);
        // Post every day, so every day in the next 7 is a gap to fill (deterministic count).
        $brand->update(['posting_goal' => ['posts_per_week' => null, 'preferred_days' => [1, 2, 3, 4, 5, 6, 7]]]);
        $owner = User::factory()->create(['org_id' => $organization->id, 'role' => Role::Owner, 'email_verified_at' => now()]);

        return [$organization, $brand->refresh(), $owner];
    }

    private function fakeProvider(int $ideaCount = 2): void
    {
        $this->app->instance(LocalIdeaProvider::class, new class($ideaCount) implements LocalIdeaProvider
        {
            public function __construct(private readonly int $ideaCount) {}

            public function recommendLocalPosts(Client $client, IdeaContext $context): AiPostIdeas
            {
                return new AiPostIdeas(array_map(
                    fn (int $n) => new AiPostIdeaDto(title: "Idea {$n}", description: "Description {$n}", platforms: ['instagram'], suggestedCaption: "Caption {$n}"),
                    range(1, max(1, $this->ideaCount)),
                ));
            }
        });
    }

    public function test_it_fills_every_gap_day_in_the_next_week(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'UTC'));
        [, $brand] = $this->businessWorkspace();
        $this->fakeProvider();

        $plan = app(GenerateWeeklyPlanAction::class)($brand);

        $this->assertCount(7, $plan);
        $this->assertSame(7, AiPostIdea::query()->where('client_id', $brand->id)->whereNotNull('planned_for')->count());

        foreach ($plan as $item) {
            $this->assertNotNull($item['plannedFor']);
            $this->assertNotNull($item['slotLabel']);
            $this->assertSame(['instagram'], $item['platforms']);
        }
    }

    public function test_the_default_slot_hour_is_used_without_engagement_data(): void
    {
        config(['ai.plan.default_hour' => 9]);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'UTC'));
        [, $brand] = $this->businessWorkspace('UTC');
        $this->fakeProvider();

        app(GenerateWeeklyPlanAction::class)($brand);

        $item = AiPostIdea::query()->where('client_id', $brand->id)->whereNotNull('planned_for')->first();
        $this->assertSame(9, CarbonImmutable::parse($item->suggested_post_at)->setTimezone('UTC')->hour);
    }

    public function test_it_is_idempotent_across_runs(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'UTC'));
        [, $brand] = $this->businessWorkspace();
        $this->fakeProvider();

        $action = app(GenerateWeeklyPlanAction::class);
        $action($brand);
        $action($brand);

        $this->assertSame(7, AiPostIdea::query()->where('client_id', $brand->id)->whereNotNull('planned_for')->count());
    }

    public function test_no_goal_means_no_plan(): void
    {
        [, $brand] = $this->businessWorkspace();
        $brand->update(['posting_goal' => null]);
        $this->fakeProvider();

        $this->assertSame([], app(GenerateWeeklyPlanAction::class)($brand->refresh()));
        $this->assertSame(0, AiPostIdea::query()->where('client_id', $brand->id)->whereNotNull('planned_for')->count());
    }

    public function test_accepting_items_turns_them_into_draft_posts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'UTC'));
        config(['ai.enabled' => true]);
        [, $brand, $owner] = $this->businessWorkspace();
        $this->fakeProvider();
        app(GenerateWeeklyPlanAction::class)($brand);

        $ids = AiPostIdea::query()->where('client_id', $brand->id)->whereNotNull('planned_for')->pluck('id')->take(2)->all();

        $this->actingAs($owner)
            ->postJson(route('business.plan.accept'), ['ids' => $ids])
            ->assertOk()
            ->assertJsonPath('accepted', 2);

        $this->assertSame(2, Post::query()->where('client_id', $brand->id)->where('status', PostStatus::Idea)->count());
        // Accepted items have a post_id, so they leave the plan.
        $this->assertSame(5, AiPostIdea::query()->where('client_id', $brand->id)->whereNotNull('planned_for')->whereNull('post_id')->count());
    }

    public function test_discarding_items_removes_them(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'UTC'));
        config(['ai.enabled' => true]);
        [, $brand, $owner] = $this->businessWorkspace();
        $this->fakeProvider();
        app(GenerateWeeklyPlanAction::class)($brand);

        $ids = AiPostIdea::query()->where('client_id', $brand->id)->whereNotNull('planned_for')->pluck('id')->all();

        $this->actingAs($owner)
            ->postJson(route('business.plan.discard'), ['ids' => $ids])
            ->assertOk()
            ->assertJsonPath('discarded', 7);

        $this->assertSame(0, AiPostIdea::query()->where('client_id', $brand->id)->whereNotNull('planned_for')->count());
    }

    public function test_generate_endpoint_is_404_when_ai_is_disabled(): void
    {
        config(['ai.enabled' => false]);
        [, , $owner] = $this->businessWorkspace();

        $this->actingAs($owner)->postJson(route('business.plan.generate'))->assertNotFound();
    }

    public function test_a_viewer_cannot_generate_a_plan(): void
    {
        config(['ai.enabled' => true]);
        [$org] = $this->businessWorkspace();
        $viewer = User::factory()->create(['org_id' => $org->id, 'role' => Role::Viewer, 'email_verified_at' => now()]);

        $this->actingAs($viewer)->postJson(route('business.plan.generate'))->assertForbidden();
    }
}
