<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignBillingType;
use App\Enums\InvoiceStatus;
use App\Models\AiUsageLog;
use App\Models\Campaign;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Campaigns\Concerns\BuildsCampaigns;
use Tests\TestCase;

/**
 * Campaigns enhancement C-8.3 — campaign profitability (invoiced, paid, AI spend) and revenue by
 * campaign in org analytics, behind the existing billing and AI-cost rules.
 */
class CampaignProfitabilityTest extends TestCase
{
    use BuildsCampaigns, RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampaignOrg();
        $this->travelTo(now()->setDate(2026, 10, 19));
        $this->campaign = $this->makeCampaign(['name' => 'Spring launch', 'billing_type' => CampaignBillingType::FixedFee, 'fee_amount' => 3000]);

        $paid = Invoice::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => InvoiceStatus::Paid, 'currency' => 'GBP', 'issue_date' => '2026-10-05', 'paid_at' => '2026-10-10 10:00:00']);
        $sent = Invoice::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => InvoiceStatus::Sent, 'currency' => 'GBP', 'issue_date' => '2026-10-12']);
        InvoiceItem::factory()->create(['invoice_id' => $paid->id, 'org_id' => $this->org->id, 'campaign_id' => $this->campaign->id, 'price' => 1500, 'quantity' => 1]);
        InvoiceItem::factory()->create(['invoice_id' => $sent->id, 'org_id' => $this->org->id, 'campaign_id' => $this->campaign->id, 'price' => 500, 'quantity' => 2]);

        foreach ([0.002, 0.003] as $cost) {
            AiUsageLog::query()->create(['org_id' => $this->org->id, 'client_id' => $this->client->id, 'campaign_id' => $this->campaign->id, 'provider' => 'deepseek', 'model' => 'deepseek-flash', 'method' => 'planCampaign', 'succeeded' => true, 'cost_estimate' => $cost, 'duration_ms' => 100]);
        }
    }

    public function test_the_owner_sees_invoiced_paid_and_ai_spend_matching_the_logs(): void
    {
        $this->actingAs($this->owner())->get(route('campaigns.show', $this->campaign))
            ->assertInertia(fn (Assert $page) => $page
                ->where('campaign.billing.invoiced_amount', '2500.00')
                ->where('campaign.billing.paid_amount', '1500.00')
                ->where('campaign.billing.ai_cost_estimate', fn ($cost) => abs((float) $cost - (float) AiUsageLog::query()->where('campaign_id', $this->campaign->id)->sum('cost_estimate')) < 0.00001));
    }

    public function test_a_strategist_sees_the_money_but_not_ai_spend(): void
    {
        $this->actingAs($this->strategist())->get(route('campaigns.show', $this->campaign))
            ->assertInertia(fn (Assert $page) => $page
                ->where('campaign.billing.paid_amount', '1500.00')
                ->where('campaign.billing.ai_cost_estimate', null));
    }

    public function test_designers_and_viewers_get_no_commercials_at_all(): void
    {
        foreach ([$this->designer(), $this->viewer()] as $user) {
            $this->actingAs($user)->get(route('campaigns.show', $this->campaign))
                ->assertInertia(fn (Assert $page) => $page->where('campaign.billing', null));
        }
    }

    public function test_org_analytics_breaks_revenue_down_by_campaign(): void
    {
        $this->actingAs($this->owner())->get(route('analytics.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('summary.revenue.by_campaign', 1)
                ->where('summary.revenue.by_campaign.0.name', 'Spring launch')
                ->where('summary.revenue.by_campaign.0.currency', 'GBP')
                ->where('summary.revenue.by_campaign.0.total', fn ($total) => (float) $total === 2500.0));

        $this->actingAs($this->designer())->get(route('analytics.index'))
            ->assertInertia(fn (Assert $page) => $page->where('summary.revenue', null));
    }

    public function test_campaign_ai_calls_record_an_estimated_cost_only_when_deepseek_succeeds(): void
    {
        config(['ai.enabled' => true, 'ai.deepseek.api_key' => '']);
        $campaign = $this->makeCampaign();

        $this->actingAs($this->strategist())->postJson(route('campaigns.ai.brief', $campaign))->assertOk();

        $this->assertDatabaseHas('ai_usage_logs', ['campaign_id' => $campaign->id, 'provider' => 'null', 'cost_estimate' => 0]);
    }
}
