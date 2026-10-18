<?php

namespace Tests\Feature\Campaigns;

use App\Actions\Campaigns\MarkCampaignWentLiveAction;
use App\Actions\Campaigns\SendDeliverableDueRemindersAction;
use App\Contracts\CampaignAiProvider;
use App\Enums\CampaignStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\Asset;
use App\Models\BrandBrain;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\Client;
use App\Models\Post;
use App\Notifications\CampaignDeliverableAssigned;
use App\Notifications\CampaignWentLive;
use App\Support\AI\Campaigns\AiCampaignBriefDraft;
use App\Support\AI\Campaigns\AiCampaignWrapDraft;
use App\Support\AI\Campaigns\AiDeliverableSuggestion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Campaigns\Concerns\BuildsCampaigns;
use Tests\TestCase;

/**
 * Campaigns enhancement C-3.3 / C-4.3 / C-6.3 — the moving parts: went-live fires once, due
 * reminders skip weekends and only reach the assignee, the AI planner drops gated platforms and
 * flags (never drops) banned phrases without saving anything, the asset kit is non-destructive,
 * and the post editor only accepts the client's own campaigns.
 */
class CampaignOperationsTest extends TestCase
{
    use BuildsCampaigns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampaignOrg();
        Notification::fake();
    }

    public function test_going_live_is_recorded_and_announced_once(): void
    {
        $campaign = $this->makeCampaign(['status' => CampaignStatus::Active, 'owner_id' => $this->strategist()->id]);
        $first = Post::factory()->for($this->client)->create(['campaign_id' => $campaign->id, 'status' => PostStatus::Published]);
        $second = Post::factory()->for($this->client)->create(['campaign_id' => $campaign->id, 'status' => PostStatus::Published]);

        app(MarkCampaignWentLiveAction::class)($first);
        app(MarkCampaignWentLiveAction::class)($second);

        $this->assertSame(1, $campaign->activityLogs()->where('event', 'campaign.went_live')->count());
        Notification::assertSentToTimes($this->strategist(), CampaignWentLive::class, 1);
        Notification::assertNotSentTo($this->owner(), CampaignWentLive::class);
    }

    public function test_due_reminders_count_working_days_and_only_reach_the_assignee(): void
    {
        $campaign = $this->makeCampaign(['status' => CampaignStatus::Active]);
        $this->client->update(['approval_lead_days' => 1]);
        // Planned Monday 26 Oct with one working day of lead time: due Friday 23 Oct, not Sunday.
        CampaignDeliverable::factory()->for($campaign)->create(['assignee_id' => $this->designer()->id, 'planned_for' => '2026-10-26', 'platform' => Platform::Instagram, 'post_id' => null]);

        $sent = app(SendDeliverableDueRemindersAction::class)(CarbonImmutable::parse('2026-10-22 08:15', 'UTC'));

        $this->assertSame(['due_tomorrow' => 1, 'overdue' => 0], $sent);
        Notification::assertSentTo($this->designer(), CampaignDeliverableAssigned::class);
        Notification::assertNotSentTo($this->owner(), CampaignDeliverableAssigned::class);

        // On the due day itself nothing goes out; the day after, it's overdue.
        $this->assertSame(['due_tomorrow' => 0, 'overdue' => 0], app(SendDeliverableDueRemindersAction::class)(CarbonImmutable::parse('2026-10-23 08:15', 'UTC')));
        $this->assertSame(['due_tomorrow' => 0, 'overdue' => 1], app(SendDeliverableDueRemindersAction::class)(CarbonImmutable::parse('2026-10-24 08:15', 'UTC')));
    }

    public function test_due_reminders_skip_finished_work_and_paused_campaigns(): void
    {
        $this->client->update(['approval_lead_days' => 1]);
        $paused = $this->makeCampaign(['status' => CampaignStatus::Paused]);
        CampaignDeliverable::factory()->for($paused)->create(['assignee_id' => $this->designer()->id, 'planned_for' => '2026-10-26', 'platform' => Platform::Instagram, 'post_id' => null]);

        $live = $this->makeCampaign(['status' => CampaignStatus::Active]);
        $approved = Post::factory()->for($this->client)->create(['campaign_id' => $live->id, 'status' => PostStatus::Approved]);
        CampaignDeliverable::factory()->for($live)->create(['assignee_id' => $this->designer()->id, 'planned_for' => '2026-10-26', 'platform' => Platform::Instagram, 'post_id' => $approved->id]);

        $this->assertSame(['due_tomorrow' => 0, 'overdue' => 0], app(SendDeliverableDueRemindersAction::class)(CarbonImmutable::parse('2026-10-22 08:15', 'UTC')));
        Notification::assertNothingSent();
    }

    public function test_the_ai_planner_drops_gated_platforms_and_flags_banned_phrases_without_saving(): void
    {
        config(['ai.enabled' => true, 'publishing.rollout.tiktok' => false]);
        BrandBrain::factory()->for($this->client)->create(['voice' => ['do_nots' => ['cheap']]]);
        $campaign = $this->makeCampaign(['platforms' => ['instagram', 'tiktok'], 'start_date' => '2026-11-02', 'end_date' => '2026-11-29']);

        $this->app->instance(CampaignAiProvider::class, $this->fakeAi([
            new AiDeliverableSuggestion('Cheap thrills reel', 'instagram', 'reel', null, null, '2026-11-03', '09:00', 'A cheap laugh'),
            new AiDeliverableSuggestion('Dance trend', 'tiktok', 'video', null, null, '2026-11-04', null, null),
            new AiDeliverableSuggestion('Out of range', 'instagram', 'post', null, null, '2027-01-04', null, null),
        ]));

        $this->actingAs($this->strategist())->postJson(route('campaigns.ai.plan', $campaign))
            ->assertOk()
            ->assertJsonCount(1, 'suggestions')
            ->assertJsonPath('suggestions.0.platform', 'instagram')
            ->assertJsonPath('suggestions.0.flags', ['cheap']);

        $this->assertDatabaseCount('campaign_deliverables', 0);
        $this->assertDatabaseHas('ai_usage_logs', ['campaign_id' => $campaign->id, 'method' => 'planCampaign', 'succeeded' => true]);
    }

    public function test_the_null_ai_provider_answers_with_an_empty_draft(): void
    {
        config(['ai.enabled' => true, 'ai.deepseek.api_key' => '']);
        $campaign = $this->makeCampaign();

        $this->actingAs($this->strategist())->postJson(route('campaigns.ai.brief', $campaign))->assertOk()->assertJsonStructure(['draft']);
        $this->assertDatabaseCount('campaign_briefs', 0);
    }

    public function test_campaign_ai_is_hidden_when_ai_is_off_and_forbidden_to_designers(): void
    {
        $campaign = $this->makeCampaign();

        config(['ai.enabled' => false]);
        $this->actingAs($this->strategist())->postJson(route('campaigns.ai.brief', $campaign))->assertNotFound();

        config(['ai.enabled' => true]);
        $this->actingAs($this->designer())->postJson(route('campaigns.ai.brief', $campaign))->assertForbidden();
    }

    public function test_the_asset_kit_tags_and_untags_without_deleting(): void
    {
        $campaign = $this->makeCampaign();
        $asset = Asset::factory()->for($this->client)->create(['org_id' => $this->org->id]);

        $this->actingAs($this->designer())->post(route('campaigns.kit.store', $campaign), ['asset_ids' => [$asset->id]])->assertSessionHasNoErrors();
        $this->assertSame($campaign->id, $asset->refresh()->campaign_id);

        $this->actingAs($this->designer())->delete(route('campaigns.kit.destroy', [$campaign, $asset]))->assertSessionHasNoErrors();
        $this->assertNull($asset->refresh()->campaign_id);
        $this->assertNotSoftDeleted($asset);
    }

    public function test_the_asset_kit_refuses_another_clients_assets(): void
    {
        $campaign = $this->makeCampaign();
        $other = Client::factory()->for($this->org, 'organization')->create();
        $asset = Asset::factory()->for($other)->create(['org_id' => $this->org->id]);

        $this->actingAs($this->owner())->post(route('campaigns.kit.store', $campaign), ['asset_ids' => [$asset->id]])->assertSessionHasErrors('asset_ids.0');
        $this->assertNull($asset->refresh()->campaign_id);
    }

    public function test_the_post_editor_rejects_another_clients_campaign(): void
    {
        $other = Client::factory()->for($this->org, 'organization')->create();
        $theirs = Campaign::factory()->for($other)->create(['org_id' => $this->org->id]);
        $post = Post::factory()->for($this->client)->create(['status' => PostStatus::Draft]);

        $this->actingAs($this->owner())->put(route('posts.update', $post), [
            'master_caption' => 'Hello',
            'campaign_id' => $theirs->id,
        ])->assertSessionHasErrors('campaign_id');

        $this->assertNull($post->refresh()->campaign_id);
    }

    /**
     * @param  list<AiDeliverableSuggestion>  $suggestions
     */
    private function fakeAi(array $suggestions): CampaignAiProvider
    {
        return new class($suggestions) implements CampaignAiProvider
        {
            /** @param list<AiDeliverableSuggestion> $suggestions */
            public function __construct(private array $suggestions) {}

            public function draftCampaignBrief(Campaign $campaign): AiCampaignBriefDraft
            {
                throw new \LogicException('Not used.');
            }

            public function planCampaign(Campaign $campaign, array $input): array
            {
                return $this->suggestions;
            }

            public function draftCampaignWrap(Campaign $campaign, array $facts): AiCampaignWrapDraft
            {
                throw new \LogicException('Not used.');
            }
        };
    }
}
