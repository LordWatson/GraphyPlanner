<?php

namespace Tests\Unit\Services;

use App\Actions\Posts\DeletePostAction;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\CampaignReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Campaigns\Concerns\BuildsCampaigns;
use Tests\TestCase;

class CampaignReadinessServiceTest extends TestCase
{
    use BuildsCampaigns, RefreshDatabase;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampaignOrg();
        $this->today = CarbonImmutable::parse('2026-10-18');
        SocialAccount::factory()->for($this->client)->create(['platform' => Platform::Instagram, 'connection_status' => ConnectionStatus::Connected]);
    }

    private function deliverable(Campaign $campaign, string $plannedFor, ?PostStatus $status = null, array $extra = []): CampaignDeliverable
    {
        $post = $status ? Post::factory()->for($this->client)->create(['campaign_id' => $campaign->id, 'status' => $status]) : null;

        return CampaignDeliverable::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => Platform::Instagram,
            'planned_for' => $plannedFor,
            'post_id' => $post?->id,
            ...$extra,
        ]);
    }

    public function test_a_campaign_with_no_deliverables_is_not_planned_rather_than_zero(): void
    {
        $readiness = app(CampaignReadinessService::class)->compute($this->makeCampaign(), $this->today);

        $this->assertNull($readiness->percent);
        $this->assertSame('Not planned', $readiness->label());
        $this->assertSame([], $readiness->blockers);
    }

    public function test_readiness_weights_work_that_is_due_soon(): void
    {
        $campaign = $this->makeCampaign(['start_date' => '2026-10-20']);
        // Due within a week (×3), approved.
        $this->deliverable($campaign, '2026-10-21', PostStatus::Approved);
        // Due in a month (×1), not started.
        $this->deliverable($campaign, '2026-11-20');

        $readiness = app(CampaignReadinessService::class)->compute($campaign, $this->today);

        // 3 / (3 + 1) = 75%, where an unweighted share would be 50%.
        $this->assertSame(75, $readiness->percent);
    }

    public function test_scheduled_and_published_count_as_ready_but_failed_does_not(): void
    {
        $campaign = $this->makeCampaign();
        $this->deliverable($campaign, '2026-10-19', PostStatus::Scheduled);
        $this->deliverable($campaign, '2026-10-19', PostStatus::Published);
        $this->deliverable($campaign, '2026-10-19', PostStatus::Failed);

        $readiness = app(CampaignReadinessService::class)->compute($campaign, $this->today);

        $this->assertSame(67, $readiness->percent);
        $this->assertContains('1 post failed to publish.', array_column($readiness->blockers, 'reason'));
    }

    public function test_blockers_are_listed_in_plain_words(): void
    {
        $campaign = $this->makeCampaign(['start_date' => '2026-10-22']);
        $this->deliverable($campaign, '2026-10-22', PostStatus::InternalReview);
        $this->deliverable($campaign, '2026-10-22', PostStatus::WaitingClient);
        $this->deliverable($campaign, '2026-10-23', null, ['asset_requirements' => [['label' => '1 × 9:16 video', 'met' => false], ['label' => 'Logo', 'met' => true]]]);
        $this->deliverable($campaign, '2026-10-23', null, ['platform' => Platform::LinkedIn]);

        $reasons = array_column(app(CampaignReadinessService::class)->compute($campaign, $this->today)->blockers, 'reason');

        $this->assertContains('No LinkedIn account connected.', $reasons);
        $this->assertContains('2 deliverables not started, launch in 4 days.', $reasons);
        $this->assertContains('2 posts still in review, launch in 4 days.', $reasons);
        $this->assertContains('1 asset requirement unmet.', $reasons);
    }

    public function test_a_deleted_post_frees_its_deliverable(): void
    {
        $campaign = $this->makeCampaign();
        $deliverable = $this->deliverable($campaign, '2026-10-19', PostStatus::Draft);

        app(DeletePostAction::class)($deliverable->post, $this->owner());

        $this->assertNull($deliverable->fresh()->post_id);
        $readiness = app(CampaignReadinessService::class)->compute($campaign, $this->today);
        $this->assertSame(1, $readiness->counts['not_started']);
    }
}
