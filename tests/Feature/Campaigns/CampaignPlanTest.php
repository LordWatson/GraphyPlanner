<?php

namespace Tests\Feature\Campaigns;

use App\Enums\ConnectionStatus;
use App\Enums\DeliverableState;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\CampaignPhase;
use App\Models\Client;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Notifications\CampaignDeliverableAssigned;
use App\Services\CampaignKpiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Campaigns\Concerns\BuildsCampaigns;
use Tests\TestCase;

/**
 * Steps C-3.1 (phases) and C-3.2 (deliverables matrix + start post), with the board move (C-4.3).
 */
class CampaignPlanTest extends TestCase
{
    use BuildsCampaigns, RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampaignOrg();
        $this->campaign = $this->makeCampaign(['start_date' => '2026-11-01', 'end_date' => '2026-11-28']);
    }

    public function test_a_phase_must_sit_within_the_campaign_dates(): void
    {
        $this->actingAs($this->owner())->post(route('campaigns.phases.store', $this->campaign), [
            'name' => 'Teaser', 'starts_on' => '2026-10-25', 'ends_on' => '2026-11-05',
        ])->assertSessionHasErrors('starts_on');

        $this->actingAs($this->owner())->post(route('campaigns.phases.store', $this->campaign), [
            'name' => 'Wrap', 'starts_on' => '2026-11-20', 'ends_on' => '2026-12-02',
        ])->assertSessionHasErrors('ends_on');

        $this->actingAs($this->owner())->post(route('campaigns.phases.store', $this->campaign), [
            'name' => 'Launch', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-07', 'colour' => 'teal',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Launch'], $this->campaign->phases()->pluck('name')->all());
    }

    public function test_an_undated_campaign_takes_undated_phases(): void
    {
        $campaign = $this->makeCampaign(['start_date' => null, 'end_date' => null]);

        $this->actingAs($this->owner())->post(route('campaigns.phases.store', $campaign), ['name' => 'Teaser'])->assertSessionHasNoErrors();
    }

    public function test_suggested_phases_are_applied_once_on_a_click(): void
    {
        $this->actingAs($this->owner())->post(route('campaigns.phases.suggested', $this->campaign))->assertSessionHasNoErrors();
        $this->assertSame(['Teaser', 'Launch', 'Sustain', 'Wrap'], $this->campaign->phases()->pluck('name')->all());

        $this->actingAs($this->owner())->post(route('campaigns.phases.suggested', $this->campaign))->assertSessionHasErrors('phases');
        $this->assertSame(4, $this->campaign->phases()->count());
    }

    public function test_reorder_is_atomic_and_must_list_every_phase(): void
    {
        $a = CampaignPhase::factory()->create(['campaign_id' => $this->campaign->id, 'name' => 'A', 'sort' => 1]);
        $b = CampaignPhase::factory()->create(['campaign_id' => $this->campaign->id, 'name' => 'B', 'sort' => 2]);
        $c = CampaignPhase::factory()->create(['campaign_id' => $this->campaign->id, 'name' => 'C', 'sort' => 3]);

        // A partial order would leave two phases sharing a slot: rejected, nothing written.
        $this->actingAs($this->owner())
            ->post(route('campaigns.phases.reorder', $this->campaign), ['order' => [$c->id, $a->id]]);
        $this->assertSame(['A', 'B', 'C'], $this->campaign->phases()->pluck('name')->all());

        $this->actingAs($this->owner())->post(route('campaigns.phases.reorder', $this->campaign), ['order' => [$c->id, $a->id, $b->id]]);
        $this->assertSame(['C', 'A', 'B'], $this->campaign->phases()->pluck('name')->all());
    }

    public function test_a_phase_from_another_campaign_is_a_404(): void
    {
        $other = $this->makeCampaign();
        $phase = CampaignPhase::factory()->create(['campaign_id' => $other->id]);

        $this->actingAs($this->owner())->put(route('campaigns.phases.update', [$this->campaign, $phase]), ['name' => 'X'])->assertNotFound();
    }

    public function test_a_deliverable_is_planned_started_and_followed_to_published(): void
    {
        $account = SocialAccount::factory()->for($this->client)->create(['platform' => Platform::Instagram, 'connection_status' => ConnectionStatus::Connected, 'timezone' => 'Europe/London']);

        $this->actingAs($this->owner())->post(route('campaigns.deliverables.store', $this->campaign), [
            'title' => 'Launch reel',
            'platform' => 'instagram',
            'planned_for' => '2026-11-03',
            'planned_time' => '09:30',
        ])->assertSessionHasNoErrors();

        $deliverable = $this->campaign->deliverables()->firstOrFail();
        $this->assertSame(DeliverableState::NotStarted, $deliverable->state());

        // Designers produce, so they can start it.
        $response = $this->actingAs($this->designer())->post(route('campaigns.deliverables.start', [$this->campaign, $deliverable]));

        $deliverable->refresh()->load('post');
        $post = $deliverable->post;
        $response->assertRedirect(route('posts.edit', $post));
        $this->assertSame(PostStatus::Idea, $post->status);
        $this->assertSame('Launch reel', $post->master_caption);
        $this->assertSame($this->campaign->id, $post->campaign_id);
        $target = $post->targets()->firstOrFail();
        $this->assertSame($account->id, $target->social_account_id);
        $this->assertSame('2026-11-03', $target->scheduled_local_date?->toDateString());
        $this->assertSame('2026-11-03 09:30:00', $target->scheduled_at_utc?->toDateTimeString());

        $expected = [
            PostStatus::Draft->value => DeliverableState::InProduction,
            PostStatus::InternalReview->value => DeliverableState::InReview,
            PostStatus::Approved->value => DeliverableState::Approved,
            PostStatus::Scheduled->value => DeliverableState::Scheduled,
            PostStatus::Published->value => DeliverableState::Published,
        ];

        foreach ($expected as $status => $state) {
            $post->update(['status' => $status]);
            $this->assertSame($state, $deliverable->fresh()->load('post')->state(), $status);
        }
    }

    public function test_starting_twice_is_rejected(): void
    {
        $deliverable = CampaignDeliverable::factory()->create(['campaign_id' => $this->campaign->id, 'platform' => Platform::LinkedIn]);

        $this->actingAs($this->owner())->post(route('campaigns.deliverables.start', [$this->campaign, $deliverable]));
        $this->actingAs($this->owner())->post(route('campaigns.deliverables.start', [$this->campaign, $deliverable]))->assertSessionHasErrors('deliverable');

        $this->assertSame(1, Post::query()->count());
    }

    public function test_several_accounts_on_a_platform_need_a_choice(): void
    {
        SocialAccount::factory()->count(2)->for($this->client)->create(['platform' => Platform::Instagram]);
        $deliverable = CampaignDeliverable::factory()->create(['campaign_id' => $this->campaign->id, 'platform' => Platform::Instagram]);

        $this->actingAs($this->owner())->post(route('campaigns.deliverables.start', [$this->campaign, $deliverable]))->assertSessionHasErrors('social_account_id');

        $foreignOrg = $this->foreignOwner()->organization;
        $foreign = SocialAccount::factory()->create([
            'org_id' => $foreignOrg->id,
            'client_id' => Client::factory()->for($foreignOrg, 'organization')->create()->id,
            'platform' => Platform::Instagram,
        ]);
        $this->actingAs($this->owner())->post(route('campaigns.deliverables.start', [$this->campaign, $deliverable]), ['social_account_id' => $foreign->id])->assertSessionHasErrors('social_account_id');

        $this->assertSame(0, Post::query()->count());
    }

    public function test_deleting_the_post_returns_the_deliverable_to_not_started(): void
    {
        $deliverable = CampaignDeliverable::factory()->create(['campaign_id' => $this->campaign->id, 'platform' => Platform::LinkedIn]);
        $this->actingAs($this->owner())->post(route('campaigns.deliverables.start', [$this->campaign, $deliverable]));
        $post = $deliverable->fresh()->post;

        $this->actingAs($this->owner())->delete(route('posts.destroy', $post));

        $this->assertSoftDeleted($post);
        $this->assertSame(DeliverableState::NotStarted, $deliverable->fresh()->state());

        // …and it can be started again.
        $this->actingAs($this->owner())->post(route('campaigns.deliverables.start', [$this->campaign, $deliverable]))->assertSessionHasNoErrors();
    }

    public function test_viewers_cannot_plan_or_start(): void
    {
        $deliverable = CampaignDeliverable::factory()->create(['campaign_id' => $this->campaign->id]);

        $this->actingAs($this->viewer())->post(route('campaigns.deliverables.store', $this->campaign), ['title' => 'X', 'platform' => 'instagram'])->assertForbidden();
        $this->actingAs($this->viewer())->post(route('campaigns.deliverables.start', [$this->campaign, $deliverable]))->assertForbidden();
        $this->actingAs($this->designer())->post(route('campaigns.deliverables.store', $this->campaign), ['title' => 'X', 'platform' => 'instagram'])->assertForbidden();
    }

    public function test_a_designer_may_only_tick_asset_requirements(): void
    {
        $deliverable = CampaignDeliverable::factory()->create(['campaign_id' => $this->campaign->id, 'asset_requirements' => [['label' => '1 × 9:16 video', 'met' => false]]]);

        $this->actingAs($this->designer())->patch(route('campaigns.deliverables.update', [$this->campaign, $deliverable]), [
            'asset_requirements' => [['label' => '1 × 9:16 video', 'met' => true]],
        ])->assertSessionHasNoErrors();
        $this->assertTrue($deliverable->fresh()->asset_requirements[0]['met']);

        $this->actingAs($this->designer())->patch(route('campaigns.deliverables.update', [$this->campaign, $deliverable]), [
            'title' => 'Renamed',
        ])->assertForbidden();
    }

    public function test_bulk_add_spreads_slots_across_the_phase(): void
    {
        $this->connect(Platform::Instagram, Platform::LinkedIn);
        $phase = CampaignPhase::factory()->create(['campaign_id' => $this->campaign->id, 'name' => 'Launch', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-15']);

        $this->actingAs($this->owner())->post(route('campaigns.deliverables.bulk', $this->campaign), [
            'per_week' => 2,
            'platforms' => ['instagram', 'linkedin'],
            'campaign_phase_id' => $phase->id,
            'planned_time' => '10:00',
        ])->assertSessionHasNoErrors();

        $deliverables = $this->campaign->deliverables()->orderBy('planned_for')->get();
        // 2 weeks × 2 per week × 2 platforms.
        $this->assertCount(8, $deliverables);
        $this->assertTrue($deliverables->every(fn ($d) => $d->campaign_phase_id === $phase->id));
        $this->assertSame('2026-11-02', $deliverables->first()->planned_for->toDateString());
        $this->assertTrue($deliverables->every(fn ($d) => $d->planned_for->toDateString() <= '2026-11-15'));
    }

    public function test_assignment_notifies_only_the_assignee(): void
    {
        $this->connect(Platform::Instagram);
        Notification::fake();

        $this->actingAs($this->owner())->post(route('campaigns.deliverables.store', $this->campaign), [
            'title' => 'Carousel', 'platform' => 'instagram', 'assignee_id' => $this->designer()->id,
        ]);

        Notification::assertSentTo($this->designer(), CampaignDeliverableAssigned::class);
        Notification::assertNotSentTo([$this->owner(), $this->strategist(), $this->viewer()], CampaignDeliverableAssigned::class);
    }

    public function test_the_board_rejects_an_illegal_move_and_accepts_a_legal_one(): void
    {
        $post = Post::factory()->for($this->client)->create(['campaign_id' => $this->campaign->id, 'status' => PostStatus::Draft]);

        $this->actingAs($this->owner())->post(route('campaigns.board.move', [$this->campaign, $post]), ['to' => 'approved'])->assertSessionHasErrors('to');
        $this->assertSame(PostStatus::Draft, $post->fresh()->status);

        $this->actingAs($this->owner())->post(route('campaigns.board.move', [$this->campaign, $post]), ['to' => 'internal_review'])->assertSessionHasNoErrors();
        $this->assertSame(PostStatus::InternalReview, $post->fresh()->status);

        $this->actingAs($this->viewer())->post(route('campaigns.board.move', [$this->campaign, $post]), ['to' => 'draft'])->assertForbidden();
    }

    public function test_the_board_only_moves_this_campaigns_posts(): void
    {
        $other = Post::factory()->for($this->client)->create(['status' => PostStatus::Draft]);

        $this->actingAs($this->owner())->post(route('campaigns.board.move', [$this->campaign, $other]), ['to' => 'internal_review'])->assertNotFound();
    }

    public function test_kpi_targets_are_replaced_in_order_and_actuals_are_honest(): void
    {
        $this->actingAs($this->owner())->put(route('campaigns.kpis.update', $this->campaign), [
            'kpis' => [
                ['metric' => 'posts_published', 'target' => 10],
                ['metric' => 'reach', 'target' => 50000],
            ],
        ])->assertSessionHasNoErrors();

        Post::factory()->count(3)->for($this->client)->create(['campaign_id' => $this->campaign->id, 'status' => PostStatus::Published]);

        $results = app(CampaignKpiService::class)->results($this->campaign->fresh());

        $this->assertSame(['posts_published', 'reach'], array_column($results, 'metric'));
        $this->assertSame(3.0, $results[0]['actual']);
        $this->assertSame(0.3, $results[0]['progress']);
        $this->assertFalse($results[0]['met']);
        // Unavailable metrics never show a zero that looks like a result.
        $this->assertNull($results[1]['actual']);
        $this->assertFalse($results[1]['available']);
        $this->assertSame('Available once engagement tracking is on.', $results[1]['note']);

        $this->actingAs($this->designer())->put(route('campaigns.kpis.update', $this->campaign), ['kpis' => []])->assertForbidden();
        $this->actingAs($this->owner())->put(route('campaigns.kpis.update', $this->campaign), [
            'kpis' => [['metric' => 'reach', 'target' => 1], ['metric' => 'reach', 'target' => 2]],
        ])->assertSessionHasErrors('kpis.0.metric');
    }
}
