<?php

namespace Tests\Feature\Campaigns;

use App\Actions\Campaigns\CampaignStatusTransitionMap;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignPhase;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Campaigns\Concerns\BuildsCampaigns;
use Tests\TestCase;

/**
 * Steps C-0.2 (data model v2) and C-0.3 (status transitions + activity log).
 */
class CampaignLifecycleTest extends TestCase
{
    use BuildsCampaigns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampaignOrg();
    }

    public function test_a_campaign_is_created_with_the_v2_fields_and_an_activity_row(): void
    {
        $this->actingAs($this->strategist())->post(route('clients.campaigns.store', $this->client), [
            'name' => 'Spring launch',
            'code' => 'spr26',
            'owner_id' => $this->strategist()->id,
            'objective' => 'launch',
            'colour' => 'lilac',
            'platforms' => ['instagram', 'tiktok'],
            'hashtags' => ['#SpringLaunch', 'springlaunch', 'NewSeason'],
            'default_link_url' => 'https://example.com/spring',
            'utm_campaign' => 'Spring-Launch',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
        ])->assertSessionHasNoErrors();

        $campaign = Campaign::query()->firstOrFail();

        $this->assertSame('SPR26', $campaign->code);
        $this->assertSame(['SpringLaunch', 'NewSeason'], $campaign->hashtags);
        $this->assertSame('spring-launch', $campaign->utm_campaign);
        $this->assertSame(['instagram', 'tiktok'], $campaign->platforms);
        $this->assertDatabaseHas('campaign_activity_logs', ['campaign_id' => $campaign->id, 'event' => 'campaign.created', 'user_id' => $this->strategist()->id]);
    }

    public function test_an_owner_from_another_organization_is_rejected(): void
    {
        $this->actingAs($this->owner())->post(route('clients.campaigns.store', $this->client), [
            'name' => 'Spring launch',
            'owner_id' => $this->foreignOwner()->id,
        ])->assertSessionHasErrors('owner_id');

        $this->assertDatabaseCount('campaigns', 0);
    }

    public function test_a_client_contact_cannot_own_a_campaign(): void
    {
        $this->actingAs($this->owner())->post(route('clients.campaigns.store', $this->client), [
            'name' => 'Spring launch',
            'owner_id' => $this->reviewer->id,
        ])->assertSessionHasErrors('owner_id');
    }

    public function test_a_code_is_unique_per_client_but_free_on_another_client(): void
    {
        $this->makeCampaign(['code' => 'SPR26']);

        $this->actingAs($this->owner())->post(route('clients.campaigns.store', $this->client), [
            'name' => 'Another',
            'code' => 'spr26',
        ])->assertSessionHasErrors('code');

        $other = Client::factory()->for($this->org, 'organization')->create();

        $this->actingAs($this->owner())->post(route('clients.campaigns.store', $other), [
            'name' => 'Another',
            'code' => 'SPR26',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Campaign::query()->where('code', 'SPR26')->count());
    }

    public function test_editing_a_campaign_keeps_its_own_code(): void
    {
        $campaign = $this->makeCampaign(['code' => 'SPR26']);

        $this->actingAs($this->owner())->put(route('campaigns.update', $campaign), [
            'name' => 'Renamed',
            'code' => 'SPR26',
        ])->assertSessionHasNoErrors();
    }

    public function test_invalid_enums_and_links_are_rejected(): void
    {
        $this->actingAs($this->owner())->post(route('clients.campaigns.store', $this->client), [
            'name' => 'Bad',
            'colour' => 'orange',
            'objective' => 'fame',
            'platforms' => ['myspace'],
            'default_link_url' => 'javascript:alert(1)',
            'code' => 'no spaces',
        ])->assertSessionHasErrors(['colour', 'objective', 'platforms.0', 'default_link_url', 'code']);
    }

    public function test_every_legal_transition_passes_and_logs_exactly_one_row(): void
    {
        foreach (CampaignStatusTransitionMap::MAP as $from => $targets) {
            foreach ($targets as $to) {
                $campaign = $this->makeCampaign(['status' => $from]);

                $this->actingAs($this->owner())
                    ->post(route('campaigns.transition', $campaign), ['to' => $to])
                    ->assertSessionHasNoErrors();

                $this->assertSame($to, $campaign->fresh()->status->value, "{$from} → {$to}");
                $this->assertSame(1, $campaign->activityLogs()->where('event', 'status.changed')->count());
                $this->assertDatabaseHas('campaign_activity_logs', ['campaign_id' => $campaign->id, 'from' => $from, 'to' => $to]);
            }
        }
    }

    public function test_every_illegal_transition_is_a_422_with_no_write(): void
    {
        foreach (CampaignStatus::cases() as $from) {
            foreach (CampaignStatus::cases() as $to) {
                if (CampaignStatusTransitionMap::isAllowed($from, $to)) {
                    continue;
                }

                $campaign = $this->makeCampaign(['status' => $from]);

                $this->actingAs($this->owner())
                    ->postJson(route('campaigns.transition', $campaign), ['to' => $to->value])
                    ->assertUnprocessable();

                $this->assertSame($from, $campaign->fresh()->status);
                $this->assertSame(0, $campaign->activityLogs()->count());
            }
        }
    }

    public function test_only_owners_and_strategists_can_transition(): void
    {
        $campaign = $this->makeCampaign(['status' => CampaignStatus::Planning]);

        foreach ([$this->designer(), $this->viewer(), $this->reviewer, $this->foreignOwner()] as $user) {
            // Reviewer → portal redirect (302, Step M-6.2); the others → 403. None transitions.
            $status = $this->actingAs($user)->post(route('campaigns.transition', $campaign), ['to' => 'active'])->getStatusCode();
            $this->assertContains($status, [302, 403]);
        }

        $this->actingAs($this->strategist())->post(route('campaigns.transition', $campaign), ['to' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);
    }

    public function test_campaign_dates_cannot_shrink_past_a_phase(): void
    {
        $campaign = $this->makeCampaign(['start_date' => '2026-11-01', 'end_date' => '2026-11-30']);
        CampaignPhase::factory()->create(['campaign_id' => $campaign->id, 'name' => 'Wrap', 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-30']);

        $this->actingAs($this->owner())->put(route('campaigns.update', $campaign), [
            'name' => $campaign->name,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-20',
        ])->assertSessionHasErrors(['end_date' => 'The Wrap phase runs outside these dates. Move or resize it first, or shift the whole campaign.']);

        $this->assertSame('2026-11-30', $campaign->fresh()->end_date?->toDateString());
    }
}
