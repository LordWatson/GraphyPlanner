<?php

namespace Tests\Unit\Services;

use App\Enums\BriefStatus;
use App\Enums\CampaignStatus;
use App\Enums\ClientHealth;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\CampaignBrief;
use App\Models\CampaignDeliverable;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\CampaignHealthService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Campaigns\Concerns\BuildsCampaigns;
use Tests\TestCase;

class CampaignHealthServiceTest extends TestCase
{
    use BuildsCampaigns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->setUpCampaignOrg();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): CampaignHealthService
    {
        return app(CampaignHealthService::class);
    }

    /**
     * A campaign with nothing wrong: an owner, dates, and (when Active) an approved brief.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function healthy(array $attributes = []): Campaign
    {
        $campaign = $this->makeCampaign([
            'owner_id' => $this->strategist()->id,
            'status' => CampaignStatus::Active,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            ...$attributes,
        ]);

        CampaignBrief::create(['campaign_id' => $campaign->id, 'background' => 'Context', 'status' => BriefStatus::Approved]);

        return $campaign;
    }

    /**
     * @return list<string>
     */
    private function codes(Campaign $campaign): array
    {
        return array_column($this->service()->compute($campaign->fresh())->toArray()['findings'], 'code');
    }

    public function test_a_healthy_campaign_is_green_with_a_reason(): void
    {
        $health = $this->service()->compute($this->healthy());

        $this->assertSame(ClientHealth::Green, $health->status);
        $this->assertSame('On track.', $health->reason);
    }

    public function test_completed_and_archived_campaigns_are_never_at_risk(): void
    {
        $campaign = $this->makeCampaign(['status' => CampaignStatus::Completed, 'owner_id' => null, 'start_date' => null]);

        $this->assertSame(ClientHealth::Green, $this->service()->compute($campaign)->status);
        $this->assertSame('Campaign wrapped.', $this->service()->compute($campaign)->reason);
    }

    public function test_launching_in_exactly_three_days_with_unapproved_work_is_red(): void
    {
        $campaign = $this->healthy(['status' => CampaignStatus::Planning, 'start_date' => '2026-10-21', 'end_date' => '2026-11-30']);
        Post::factory()->for($this->client)->create(['campaign_id' => $campaign->id, 'status' => PostStatus::Draft]);

        $health = $this->service()->compute($campaign);

        $this->assertSame(ClientHealth::Red, $health->status);
        $this->assertSame('Launches in 3 days and 1 piece of work isn’t approved yet.', $health->reason);
    }

    public function test_launching_in_four_days_is_not_the_red_rule(): void
    {
        $campaign = $this->healthy(['status' => CampaignStatus::Planning, 'start_date' => '2026-10-22', 'end_date' => '2026-11-30']);
        Post::factory()->for($this->client)->create(['campaign_id' => $campaign->id, 'status' => PostStatus::Draft]);

        $this->assertNotContains('launch_unapproved', $this->codes($campaign));
    }

    public function test_unstarted_deliverables_count_as_unapproved_work(): void
    {
        $campaign = $this->healthy(['status' => CampaignStatus::Planning, 'start_date' => '2026-10-18', 'end_date' => '2026-11-30']);
        CampaignDeliverable::factory()->create(['campaign_id' => $campaign->id]);

        $this->assertSame('Launches today and 1 piece of work isn’t approved yet.', $this->service()->compute($campaign)->reason);
    }

    public function test_the_launch_window_follows_the_organization_timezone(): void
    {
        // 12:00 UTC on the 18th is already the 19th in Auckland, so a launch on the 22nd is 3 days out there.
        $this->org->update(['default_timezone' => 'Pacific/Auckland']);
        $campaign = $this->healthy(['status' => CampaignStatus::Planning, 'start_date' => '2026-10-22', 'end_date' => '2026-11-30']);
        Post::factory()->for($this->client)->create(['campaign_id' => $campaign->id, 'status' => PostStatus::Draft]);

        $this->assertContains('launch_unapproved', $this->codes($campaign));
    }

    public function test_a_scheduled_post_on_an_expired_account_is_red(): void
    {
        $campaign = $this->healthy();
        $account = SocialAccount::factory()->for($this->client)->create(['connection_status' => ConnectionStatus::TokenExpired]);
        $post = Post::factory()->for($this->client)->create(['campaign_id' => $campaign->id, 'status' => PostStatus::Scheduled]);
        PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);

        $health = $this->service()->compute($campaign);

        $this->assertSame(ClientHealth::Red, $health->status);
        $this->assertSame('1 scheduled post goes to an account that needs reconnecting.', $health->reason);
    }

    public function test_still_active_exactly_seven_days_after_the_end_is_amber(): void
    {
        $campaign = $this->healthy(['end_date' => '2026-10-11']);

        $health = $this->service()->compute($campaign);

        $this->assertSame(ClientHealth::Amber, $health->status);
        $this->assertSame('Ended 7 days ago, still Active.', $health->reason);
    }

    public function test_still_active_more_than_seven_days_after_the_end_is_red(): void
    {
        $campaign = $this->healthy(['end_date' => '2026-10-10']);

        $health = $this->service()->compute($campaign);

        $this->assertSame(ClientHealth::Red, $health->status);
        $this->assertSame('Ended 8 days ago, still Active.', $health->reason);
    }

    public function test_no_owner_and_no_dates_are_amber(): void
    {
        $campaign = $this->makeCampaign(['status' => CampaignStatus::Planning, 'owner_id' => null, 'start_date' => '2026-12-01']);
        $this->assertSame(['no_owner'], $this->codes($campaign));

        $campaign = $this->makeCampaign(['status' => CampaignStatus::Planning, 'owner_id' => $this->owner()->id, 'start_date' => null]);
        $this->assertSame(['no_dates'], $this->codes($campaign));
    }

    public function test_an_active_campaign_without_an_approved_brief_is_amber(): void
    {
        $campaign = $this->makeCampaign(['status' => CampaignStatus::Active, 'owner_id' => $this->owner()->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
        $this->assertSame('Live without a brief on record.', $this->service()->compute($campaign)->reason);

        CampaignBrief::create(['campaign_id' => $campaign->id, 'background' => 'x', 'status' => BriefStatus::Sent]);
        $this->assertSame('Live, and the brief is still with the client.', $this->service()->compute($campaign->fresh())->reason);
    }

    public function test_few_deliverables_started_a_week_before_launch_is_amber(): void
    {
        $campaign = $this->healthy(['status' => CampaignStatus::Planning, 'start_date' => '2026-10-25', 'end_date' => '2026-11-30']);
        CampaignDeliverable::factory()->count(3)->create(['campaign_id' => $campaign->id]);
        $post = Post::factory()->for($this->client)->create(['campaign_id' => $campaign->id]);
        CampaignDeliverable::factory()->create(['campaign_id' => $campaign->id, 'post_id' => $post->id]);

        $this->assertContains('deliverables_not_started', $this->codes($campaign));
    }

    public function test_planned_platform_without_a_connected_account_is_amber(): void
    {
        $campaign = $this->healthy();
        CampaignDeliverable::factory()->create(['campaign_id' => $campaign->id, 'platform' => Platform::TikTok, 'planned_for' => '2026-10-29']);

        $health = $this->service()->compute($campaign);

        $this->assertSame(ClientHealth::Amber, $health->status);
        $this->assertSame('Planned for TikTok, but no account is connected.', $health->reason);
    }

    public function test_red_findings_lead_amber_ones(): void
    {
        $campaign = $this->healthy(['owner_id' => null, 'end_date' => '2026-10-01']);

        $health = $this->service()->compute($campaign);

        $this->assertSame(ClientHealth::Red, $health->status);
        $this->assertSame(['ended_still_open', 'no_owner'], array_column($health->toArray()['findings'], 'code'));
    }

    public function test_health_for_many_campaigns_costs_a_fixed_number_of_queries(): void
    {
        $count = function (): int {
            /** @var Collection<int, Campaign> $all */
            $all = Campaign::query()->withoutOrgScope()->with('organization')->get();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->service()->computeMany($all, CarbonImmutable::parse('2026-10-18'));
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        foreach (range(1, 2) as $i) {
            $campaign = $this->healthy(['status' => CampaignStatus::Planning, 'start_date' => '2026-10-20']);
            CampaignDeliverable::factory()->create(['campaign_id' => $campaign->id]);
        }
        $few = $count();

        foreach (range(1, 5) as $i) {
            $campaign = $this->healthy(['status' => CampaignStatus::Planning, 'start_date' => '2026-10-20']);
            CampaignDeliverable::factory()->create(['campaign_id' => $campaign->id]);
        }

        $this->assertSame($few, $count());
    }
}
