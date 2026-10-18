<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignBillingType;
use App\Enums\CampaignStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\CampaignComment;
use App\Models\CampaignTemplate;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Campaigns\Concerns\BuildsCampaigns;
use Tests\TestCase;

/**
 * Campaigns enhancement — what each audience is handed: workspace permissions per role, billing
 * only for Owner/Strategist, the portal's allowlist, hub filters and tenant isolation, and the
 * first-campaign template prompt (C-9.3).
 */
class CampaignWorkspacePropsTest extends TestCase
{
    use BuildsCampaigns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampaignOrg();
    }

    private function billedCampaign(): Campaign
    {
        return $this->makeCampaign([
            'status' => CampaignStatus::Active,
            'billing_type' => CampaignBillingType::FixedFee,
            'fee_amount' => 2500,
        ]);
    }

    public function test_a_designer_sees_the_workspace_without_edit_actions_or_billing(): void
    {
        $campaign = $this->billedCampaign();

        $this->actingAs($this->designer())->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('campaigns/overview')
                ->where('can.update', false)
                ->where('can.delete', false)
                ->where('can.transition', false)
                ->where('can.produce', true)
                ->where('can.viewBilling', false)
                ->where('campaign.billing', null));
    }

    public function test_owners_and_strategists_see_the_commercials(): void
    {
        $campaign = $this->billedCampaign();

        foreach ([$this->owner(), $this->strategist()] as $user) {
            $this->actingAs($user)->get(route('campaigns.show', $campaign))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('can.update', true)
                    ->where('can.viewBilling', true)
                    ->where('campaign.billing.fee_amount', fn ($fee) => (float) $fee === 2500.0));
        }
    }

    public function test_the_hub_never_carries_billing(): void
    {
        $this->billedCampaign();

        $this->actingAs($this->owner())->get(route('campaigns.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('campaigns/index')
                ->has('campaigns.data', 1)
                ->where('campaigns.data.0.billing', null));
    }

    public function test_a_client_reviewer_is_kept_out_of_the_staff_workspace(): void
    {
        $campaign = $this->billedCampaign();

        $this->actingAs($this->reviewer)->get(route('campaigns.show', $campaign))
            ->assertRedirect(route('portal.campaigns.show', $campaign));
    }

    public function test_the_portal_gets_only_the_allowlisted_campaign_fields(): void
    {
        $campaign = $this->billedCampaign();
        $campaign->update(['notes' => 'Margin is thin on this one.']);

        $this->actingAs($this->reviewer)->get(route('portal.campaigns.show', $campaign))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portal/campaigns/show')
                ->where('campaign', fn ($props) => collect($props)->keys()->sort()->values()->all() === collect([
                    'id', 'name', 'code', 'colour', 'status', 'status_label', 'start_date', 'end_date', 'goal', 'timeline',
                ])->sort()->values()->all()));

        $this->actingAs($this->reviewer)->get(route('portal.campaigns.show', $campaign))
            ->assertDontSee('Margin is thin')
            ->assertDontSee('fee_amount')
            ->assertDontSee('billing_type');
    }

    public function test_internal_comments_never_reach_the_portal(): void
    {
        $campaign = $this->billedCampaign();
        CampaignComment::query()->create(['campaign_id' => $campaign->id, 'user_id' => $this->owner()->id, 'body' => 'Client is slow, chase Tuesday.', 'internal_only' => true]);
        CampaignComment::query()->create(['campaign_id' => $campaign->id, 'user_id' => $this->owner()->id, 'body' => 'Launch moved to Monday.', 'internal_only' => false]);

        $this->actingAs($this->reviewer)->get(route('portal.campaigns.show', $campaign))
            ->assertInertia(fn (Assert $page) => $page
                ->has('comments', 1)
                ->where('comments.0.body', 'Launch moved to Monday.'));
    }

    public function test_another_clients_reviewer_gets_a_404_in_the_portal(): void
    {
        $campaign = $this->billedCampaign();
        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        $stranger = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $otherClient->id]);

        $this->actingAs($stranger)->get(route('portal.campaigns.show', $campaign))->assertNotFound();
    }

    public function test_the_hub_filters_by_status_and_client_and_isolates_tenants(): void
    {
        $this->makeCampaign(['name' => 'Live one', 'status' => CampaignStatus::Active]);
        $this->makeCampaign(['name' => 'Planned one', 'status' => CampaignStatus::Planning]);
        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        Campaign::factory()->for($otherClient)->create(['org_id' => $this->org->id, 'name' => 'Other client', 'status' => CampaignStatus::Active]);

        $foreignOrg = Organization::factory()->create();
        $foreignClient = Client::factory()->for($foreignOrg, 'organization')->create();
        Campaign::factory()->for($foreignClient)->create(['org_id' => $foreignOrg->id, 'name' => 'Not ours']);

        $names = fn (array $query) => collect($this->actingAs($this->owner())->get(route('campaigns.index', $query))
            ->viewData('page')['props']['campaigns']['data'])->pluck('name')->sort()->values()->all();

        $this->assertSame(['Live one', 'Other client', 'Planned one'], $names([]));
        $this->assertSame(['Live one', 'Other client'], $names(['status' => 'active']));
        $this->assertSame(['Live one'], $names(['status' => 'active', 'client' => $this->client->id]));
        $this->assertSame(['Planned one'], $names(['search' => 'Planned']));
    }

    public function test_the_hub_rejects_an_unknown_filter_value(): void
    {
        $this->actingAs($this->owner())->get(route('campaigns.index', ['status' => 'nonsense']))->assertSessionHasErrors('status');
    }

    public function test_a_clients_first_campaign_is_offered_templates(): void
    {
        CampaignTemplate::query()->create(['org_id' => $this->org->id, 'name' => 'Product launch', 'duration_days' => 28, 'is_starter' => true, 'phases' => [], 'deliverables' => []]);

        $this->actingAs($this->strategist())->get(route('clients.campaigns.index', $this->client))
            ->assertInertia(fn (Assert $page) => $page->has('starterTemplates', 1)->where('starterTemplates.0.name', 'Product launch'));

        $this->actingAs($this->designer())->get(route('clients.campaigns.index', $this->client))
            ->assertInertia(fn (Assert $page) => $page->where('starterTemplates', []));

        $this->makeCampaign();

        $this->actingAs($this->strategist())->get(route('clients.campaigns.index', $this->client))
            ->assertInertia(fn (Assert $page) => $page->where('starterTemplates', []));
    }

    public function test_the_first_run_empty_states_get_zero_data(): void
    {
        $this->actingAs($this->owner())->get(route('campaigns.index'))
            ->assertInertia(fn (Assert $page) => $page->component('campaigns/index')->has('campaigns.data', 0)->where('can.create', true));

        $campaign = $this->makeCampaign();

        $this->actingAs($this->owner())->get(route('campaigns.plan', $campaign))
            ->assertInertia(fn (Assert $page) => $page->component('campaigns/plan')->has('phases', 0)->has('deliverables', 0));

        $this->actingAs($this->owner())->get(route('campaigns.results', $campaign))
            ->assertInertia(fn (Assert $page) => $page->component('campaigns/results')->has('kpis', 0));
    }

    public function test_the_first_campaign_prompt_never_offers_another_organizations_templates(): void
    {
        $foreignOrg = Organization::factory()->create();
        CampaignTemplate::query()->withoutGlobalScopes()->create(['org_id' => $foreignOrg->id, 'name' => 'Theirs', 'duration_days' => 14, 'is_starter' => false, 'phases' => [], 'deliverables' => []]);

        $this->actingAs($this->strategist())->get(route('clients.campaigns.index', $this->client))
            ->assertInertia(fn (Assert $page) => $page->where('starterTemplates', []));
    }
}
