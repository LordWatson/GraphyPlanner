<?php

namespace Tests\Feature\Campaigns;

use App\Actions\Home\GetNeedsAttentionItemsAction;
use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Enums\CampaignStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Campaigns\Concerns\BuildsCampaigns;
use Tests\TestCase;

/**
 * Campaigns enhancement — where campaigns show up outside their workspace: the calendar layer
 * (C-3.5), the home needs-attention bucket (C-1.4), the editor's soft hashtag check (C-6.2) and
 * the demo data (C-9.1).
 */
class CampaignSurfacesTest extends TestCase
{
    use BuildsCampaigns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampaignOrg();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    }

    public function test_the_calendar_layer_only_carries_this_windows_campaigns_and_ghosts(): void
    {
        $inWindow = $this->makeCampaign(['name' => 'October push', 'start_date' => '2026-10-10', 'end_date' => '2026-10-30']);
        $this->makeCampaign(['name' => 'Next spring', 'start_date' => '2027-03-01', 'end_date' => '2027-03-31']);
        $this->makeCampaign(['name' => 'Archived', 'status' => CampaignStatus::Archived, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
        CampaignDeliverable::factory()->for($inWindow)->create(['planned_for' => '2026-10-21', 'platform' => Platform::Instagram, 'post_id' => null]);
        CampaignDeliverable::factory()->for($inWindow)->create(['planned_for' => '2026-12-21', 'platform' => Platform::Instagram, 'post_id' => null]);

        $foreignOrg = Organization::factory()->create();
        $foreignClient = Client::factory()->for($foreignOrg, 'organization')->create();
        Campaign::factory()->for($foreignClient)->create(['org_id' => $foreignOrg->id, 'name' => 'Not ours', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);

        $this->actingAs($this->owner())->get(route('calendar'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('campaignLayer.bands', 1)
                ->where('campaignLayer.bands.0.name', 'October push')
                ->has('campaignLayer.ghosts', 1));
    }

    public function test_a_campaign_launching_soon_with_unapproved_work_needs_attention(): void
    {
        $launching = $this->makeCampaign(['name' => 'Launching', 'status' => CampaignStatus::Planning, 'start_date' => '2026-10-21', 'end_date' => '2026-11-21']);
        Post::factory()->for($this->client)->create(['campaign_id' => $launching->id, 'status' => PostStatus::Draft]);
        $this->makeCampaign(['name' => 'Overran', 'status' => CampaignStatus::Active, 'start_date' => '2026-09-01', 'end_date' => '2026-10-01']);
        $this->makeCampaign(['name' => 'Fine', 'status' => CampaignStatus::Active, 'start_date' => '2026-10-01', 'end_date' => '2026-11-30']);

        $items = app(GetNeedsAttentionItemsAction::class)($this->owner())['campaigns'];

        $this->assertSame(2, $items['total']);
        $this->assertEqualsCanonicalizing(['Launching', 'Overran'], array_column($items['items'], 'name'));
        $this->assertSame(0, app(GetNeedsAttentionItemsAction::class)($this->reviewer)['campaigns']['total']);
    }

    public function test_the_needs_attention_bucket_runs_a_fixed_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(GetNeedsAttentionItemsAction::class)($this->owner());
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->makeCampaign(['status' => CampaignStatus::Active, 'start_date' => '2026-09-01', 'end_date' => '2026-10-01']);
        $count(); // Warm-up: the first call also lazy-loads the user's organization.
        $one = $count();

        foreach (range(1, 6) as $i) {
            $this->makeCampaign(['status' => CampaignStatus::Active, 'start_date' => '2026-09-01', 'end_date' => '2026-10-01']);
        }

        $this->assertSame($one, $count());
    }

    public function test_missing_campaign_hashtags_are_a_soft_check_that_never_blocks(): void
    {
        $campaign = $this->makeCampaign(['hashtags' => ['SpringLaunch']]);
        $post = Post::factory()->for($this->client)->create(['campaign_id' => $campaign->id, 'hashtags' => ['other']]);

        $result = app(EvaluatePostChecklistAction::class)($post);
        $item = collect($result['items'])->firstWhere('key', 'campaign_hashtags');

        $this->assertNotNull($item);
        $this->assertFalse($item['passed']);
        $this->assertTrue($item['soft']);

        $hardFailures = collect($result['items'])->reject(fn ($item) => $item['passed'] || ($item['soft'] ?? false));
        $this->assertSame($hardFailures->isEmpty(), $result['passed']);

        $post->update(['hashtags' => ['#springlaunch']]);
        $this->assertTrue(collect(app(EvaluatePostChecklistAction::class)($post->refresh())['items'])->firstWhere('key', 'campaign_hashtags')['passed']);
    }

    public function test_the_demo_seed_builds_the_campaign_showcase(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Campaign::query()->withoutGlobalScopes()->where('status', CampaignStatus::Active)->exists());
        $this->assertTrue(Campaign::query()->withoutGlobalScopes()->where('status', CampaignStatus::Paused)->exists());
        $this->assertDatabaseHas('campaign_reports', ['status' => 'shared']);
        $this->assertGreaterThan(0, DB::table('campaign_templates')->where('is_starter', true)->count());

        // C-8.3: the live showcase campaign carries its AI calls, so profitability has a figure.
        $live = DB::table('campaigns')->where('name', 'like', '%Spring Launch')->pluck('id');
        $this->assertNotEmpty($live);

        foreach ($live as $id) {
            $this->assertEqualsWithDelta(0.006, (float) DB::table('ai_usage_logs')->where('campaign_id', $id)->sum('cost_estimate'), 0.00001);
        }
    }
}
