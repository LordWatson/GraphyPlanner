<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\CampaignAiProvider;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\SocialAccount;
use App\Support\AI\Campaigns\AiCampaignBriefDraft;
use App\Support\AI\Campaigns\AiCampaignWrapDraft;
use App\Support\AI\Campaigns\AiDeliverableSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Campaigns\Concerns\BuildsCampaigns;
use Tests\TestCase;

/**
 * A campaign only plans for the client's own channels: the Plan tab's options, deliverable
 * validation and the AI planner all follow the client's social accounts.
 */
class CampaignPlatformsTest extends TestCase
{
    use BuildsCampaigns, RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampaignOrg();
        $this->campaign = $this->makeCampaign(['platforms' => [], 'start_date' => '2026-11-02', 'end_date' => '2026-11-29']);
    }

    public function test_the_plan_only_offers_the_clients_channels(): void
    {
        $this->connect(Platform::Instagram);
        SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'platform' => Platform::TikTok, 'connection_status' => ConnectionStatus::TokenExpired]);
        SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'platform' => Platform::LinkedIn, 'connection_status' => ConnectionStatus::NotConnected]);

        $this->actingAs($this->strategist())->get(route('campaigns.plan', $this->campaign))
            ->assertInertia(fn (Assert $page) => $page
                ->where('platformOptions', fn ($options) => collect($options)->pluck('value')->all() === ['instagram', 'tiktok'])
                ->where('platformLabels.linkedin', 'LinkedIn'));
    }

    public function test_a_client_with_no_accounts_has_nothing_to_plan_for(): void
    {
        $this->actingAs($this->strategist())->get(route('campaigns.plan', $this->campaign))
            ->assertInertia(fn (Assert $page) => $page->where('platformOptions', []));
    }

    public function test_publishing_switched_off_for_a_platform_removes_it(): void
    {
        $this->connect(Platform::Instagram, Platform::TikTok);
        config(['publishing.rollout.tiktok' => false]);

        $this->actingAs($this->strategist())->get(route('campaigns.plan', $this->campaign))
            ->assertInertia(fn (Assert $page) => $page->where('platformOptions', fn ($options) => collect($options)->pluck('value')->all() === ['instagram']));
    }

    public function test_a_deliverable_cannot_be_planned_for_a_channel_the_client_lacks(): void
    {
        $this->connect(Platform::Instagram);

        $this->actingAs($this->strategist())->post(route('campaigns.deliverables.store', $this->campaign), ['title' => 'Post', 'platform' => 'linkedin'])
            ->assertSessionHasErrors('platform');
        $this->actingAs($this->strategist())->post(route('campaigns.deliverables.bulk', $this->campaign), ['per_week' => 1, 'platforms' => ['instagram', 'facebook']])
            ->assertSessionHasErrors('platforms.1');

        $this->assertDatabaseCount('campaign_deliverables', 0);
    }

    public function test_an_existing_slot_keeps_its_channel_after_the_account_goes(): void
    {
        $this->connect(Platform::Instagram);
        $slot = CampaignDeliverable::factory()->for($this->campaign)->create(['platform' => Platform::LinkedIn, 'post_id' => null, 'title' => 'Old']);

        $this->actingAs($this->strategist())->put(route('campaigns.deliverables.update', [$this->campaign, $slot]), ['title' => 'Renamed', 'platform' => 'linkedin'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $slot->refresh()->title);

        $this->actingAs($this->strategist())->put(route('campaigns.deliverables.update', [$this->campaign, $slot]), ['title' => 'Moved', 'platform' => 'facebook'])
            ->assertSessionHasErrors('platform');
    }

    public function test_the_ai_planner_only_plans_for_connected_channels(): void
    {
        config(['ai.enabled' => true]);
        $this->connect(Platform::Instagram);
        $this->app->instance(CampaignAiProvider::class, new class implements CampaignAiProvider
        {
            /** @var array<string, mixed>|null */
            public static ?array $input = null;

            public function draftCampaignBrief(Campaign $campaign): AiCampaignBriefDraft
            {
                throw new \LogicException('Not used.');
            }

            public function planCampaign(Campaign $campaign, array $input): array
            {
                self::$input = $input;

                return [
                    new AiDeliverableSuggestion('Reel', 'instagram', 'reel', null, null, '2026-11-03', null, null),
                    new AiDeliverableSuggestion('Article', 'linkedin', 'post', null, null, '2026-11-04', null, null),
                ];
            }

            public function draftCampaignWrap(Campaign $campaign, array $facts): AiCampaignWrapDraft
            {
                throw new \LogicException('Not used.');
            }
        });

        $this->actingAs($this->strategist())->postJson(route('campaigns.ai.plan', $this->campaign))
            ->assertOk()
            ->assertJsonCount(1, 'suggestions')
            ->assertJsonPath('suggestions.0.platform', 'instagram');

        $ai = app(CampaignAiProvider::class);
        $this->assertSame(['instagram'], $ai::$input['platforms']);
    }

    public function test_the_ai_planner_explains_when_there_is_nothing_connected(): void
    {
        config(['ai.enabled' => true]);

        $this->actingAs($this->strategist())->postJson(route('campaigns.ai.plan', $this->campaign))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('platforms');
    }

    public function test_accepting_an_ai_plan_rejects_a_channel_the_client_lacks(): void
    {
        $this->connect(Platform::Instagram);

        $this->actingAs($this->strategist())->post(route('campaigns.plan.accept', $this->campaign), [
            'items' => [['title' => 'Article', 'platform' => 'linkedin', 'planned_for' => '2026-11-04']],
        ])->assertSessionHasErrors('items.0.platform');

        $this->assertDatabaseCount('campaign_deliverables', 0);
    }
}
