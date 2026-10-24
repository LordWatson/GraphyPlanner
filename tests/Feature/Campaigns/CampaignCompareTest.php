<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignObjective;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Campaigns\Concerns\BuildsCampaigns;
use Tests\TestCase;

/**
 * Campaigns enhancement C-7.4 — compare 2–4 campaigns; another organization's campaign is a
 * validation error, not an empty column.
 */
class CampaignCompareTest extends TestCase
{
    use BuildsCampaigns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampaignOrg();
    }

    public function test_two_campaigns_across_clients_line_up_with_objective_and_length(): void
    {
        $spring = $this->makeCampaign(['name' => 'Spring', 'objective' => CampaignObjective::cases()[0], 'start_date' => '2026-03-01', 'end_date' => '2026-03-28']);
        $other = Client::factory()->for($this->org, 'organization')->create(['name' => 'Bolt']);
        $summer = Campaign::factory()->for($other)->create(['org_id' => $this->org->id, 'name' => 'Summer', 'start_date' => '2026-06-01', 'end_date' => '2026-06-14']);
        $spring->kpis()->create(['metric' => 'posts_published', 'target' => 2, 'sort' => 1]);
        Post::factory()->count(3)->for($this->client)->create(['campaign_id' => $spring->id, 'status' => PostStatus::Published]);

        $this->actingAs($this->strategist())->get(route('campaigns.compare', ['ids' => [$spring->id, $summer->id]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('campaigns/compare')
                ->where('comparison.campaigns.0.name', 'Spring')
                ->where('comparison.campaigns.0.duration_days', 28)
                ->where('comparison.campaigns.1.client_name', 'Bolt')
                ->where('comparison.campaigns.1.duration_days', 14)
                ->where('comparison.metrics', function ($metrics) use ($spring) {
                    $published = collect($metrics)->firstWhere('metric', 'posts_published');
                    $cell = collect($published['cells'])->firstWhere('campaign_id', $spring->id);

                    return (float) $cell['target'] === 2.0 && $cell['met'] === true
                        && collect($metrics)->doesntContain('metric', 'reach');
                }));
    }

    public function test_another_organizations_campaign_is_a_validation_error(): void
    {
        $ours = $this->makeCampaign();
        $foreignOrg = Organization::factory()->create();
        $foreignClient = Client::factory()->for($foreignOrg, 'organization')->create();
        $theirs = Campaign::factory()->for($foreignClient)->create(['org_id' => $foreignOrg->id]);

        $this->actingAs($this->owner())->get(route('campaigns.compare', ['ids' => [$ours->id, $theirs->id]]))
            ->assertSessionHasErrors('ids.1');
    }

    public function test_a_single_campaign_is_a_valid_intermediate_selection(): void
    {
        $campaign = $this->makeCampaign();

        // The chip picker adds campaigns one at a time, so the first pick arrives as a single id.
        // It must render (not error) with the campaign selected and the comparison still empty.
        $this->actingAs($this->owner())->get(route('campaigns.compare', ['ids' => [$campaign->id]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected', [$campaign->id])
                ->where('comparison', null));
    }

    public function test_it_compares_at_most_four_campaigns(): void
    {
        $campaigns = collect(range(1, 5))->map(fn () => $this->makeCampaign());

        $this->actingAs($this->owner())->get(route('campaigns.compare', ['ids' => $campaigns->pluck('id')->all()]))->assertSessionHasErrors('ids');

        $this->actingAs($this->owner())->get(route('campaigns.compare'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('comparison', null)->has('options', 5));
    }

    public function test_client_reviewers_are_sent_to_their_portal(): void
    {
        $this->actingAs($this->reviewer)->get(route('campaigns.compare'))->assertRedirect(route('portal.campaigns.index'));
    }
}
