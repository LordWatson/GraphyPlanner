<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\CampaignFeatures;
use App\Enums\CampaignBillingType;
use App\Enums\CampaignFeature;
use App\Models\CampaignTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Campaigns\Concerns\BuildsCampaigns;
use Tests\TestCase;

/**
 * Campaigns enhancement C-9.4 — the entitlement seam: each gated feature answers 403 with a clear
 * message when off, and the UI is told through `can`.
 */
class CampaignFeaturesTest extends TestCase
{
    use BuildsCampaigns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampaignOrg();
    }

    private function switchOff(CampaignFeature $feature): void
    {
        $this->org->update(['campaign_features' => [$feature->value => false]]);
    }

    public function test_everything_is_on_by_default_and_an_org_can_override_the_deployment_default(): void
    {
        $features = app(CampaignFeatures::class);
        $this->assertSame(['templates' => true, 'ai_planner' => true, 'wrap_reports' => true, 'invoicing' => true], $features->all($this->org));

        config(['campaigns.features.invoicing' => false]);
        $this->assertFalse($features->enabled($this->org, CampaignFeature::Invoicing));

        $this->org->update(['campaign_features' => ['invoicing' => true]]);
        $this->assertTrue($features->enabled($this->org->refresh(), CampaignFeature::Invoicing));
    }

    public function test_templates_off_blocks_the_gallery_saving_and_using_and_hides_the_prompt(): void
    {
        $this->switchOff(CampaignFeature::Templates);
        $campaign = $this->makeCampaign(['start_date' => '2026-11-02', 'end_date' => '2026-11-29']);
        $template = CampaignTemplate::query()->create(['org_id' => $this->org->id, 'name' => 'Launch', 'duration_days' => 28, 'is_starter' => true, 'phases' => [], 'deliverables' => []]);

        $this->actingAs($this->owner())->get(route('campaign-templates.index'))->assertForbidden();
        $this->actingAs($this->owner())->postJson(route('campaigns.template.store', $campaign), ['name' => 'Copy'])
            ->assertForbidden()
            ->assertJsonPath('message', CampaignFeature::Templates->offMessage());
        $this->actingAs($this->owner())->post(route('campaign-templates.use', $template), ['client_id' => $this->client->id, 'name' => 'X', 'start_date' => '2027-01-01'])->assertForbidden();

        $this->actingAs($this->owner())->get(route('campaigns.show', $campaign))
            ->assertInertia(fn (Assert $page) => $page->where('can.saveTemplate', false)->where('can.update', true));
        $this->actingAs($this->owner())->get(route('campaigns.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.templates', false));

        $campaign->delete();
        $this->actingAs($this->owner())->get(route('clients.campaigns.index', $this->client))
            ->assertInertia(fn (Assert $page) => $page->where('starterTemplates', []));
    }

    public function test_the_ai_planner_off_is_a_403_and_hidden(): void
    {
        config(['ai.enabled' => true]);
        $this->switchOff(CampaignFeature::AiPlanner);
        $campaign = $this->makeCampaign();

        $this->actingAs($this->strategist())->postJson(route('campaigns.ai.plan', $campaign))
            ->assertForbidden()
            ->assertJsonPath('message', CampaignFeature::AiPlanner->offMessage());

        $this->actingAs($this->strategist())->get(route('campaigns.plan', $campaign))
            ->assertInertia(fn (Assert $page) => $page->where('can.aiPlanner', false)->where('can.useAi', true));
    }

    public function test_wrap_reports_off_is_a_403_and_hidden(): void
    {
        $this->switchOff(CampaignFeature::WrapReports);
        $campaign = $this->makeCampaign();

        $this->actingAs($this->strategist())->putJson(route('campaigns.report.update', $campaign), ['summary' => 'Done.'])->assertForbidden();
        $this->actingAs($this->strategist())->postJson(route('campaigns.report.share', $campaign))->assertForbidden();
        $this->assertDatabaseCount('campaign_reports', 0);

        $this->actingAs($this->strategist())->get(route('campaigns.results', $campaign))
            ->assertInertia(fn (Assert $page) => $page->where('can.wrapReports', false));
    }

    public function test_invoicing_off_is_a_403_and_hidden(): void
    {
        $this->switchOff(CampaignFeature::Invoicing);
        $campaign = $this->makeCampaign(['billing_type' => CampaignBillingType::FixedFee, 'fee_amount' => 900]);

        $this->actingAs($this->owner())->postJson(route('campaigns.invoice', $campaign))
            ->assertForbidden()
            ->assertJsonPath('message', CampaignFeature::Invoicing->offMessage());
        $this->assertDatabaseCount('invoice_items', 0);

        $this->actingAs($this->owner())->get(route('campaigns.show', $campaign))
            ->assertInertia(fn (Assert $page) => $page->where('can.invoice', false)->where('can.viewBilling', true));
    }

    public function test_a_designer_never_gets_the_planning_features_even_when_on(): void
    {
        $campaign = $this->makeCampaign();

        $this->actingAs($this->designer())->get(route('campaigns.show', $campaign))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.saveTemplate', false)
                ->where('can.aiPlanner', false)
                ->where('can.invoice', false)
                ->where('can.wrapReports', true));
    }
}
