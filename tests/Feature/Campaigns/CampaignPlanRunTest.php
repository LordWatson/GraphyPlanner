<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\CampaignAiProvider;
use App\Enums\Platform;
use App\Jobs\PlanCampaignJob;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignPlanRuns;
use App\Support\AI\Campaigns\AiCampaignBriefDraft;
use App\Support\AI\Campaigns\AiCampaignWrapDraft;
use App\Support\AI\Campaigns\AiDeliverableSuggestion;
use App\Support\Queues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Campaigns\Concerns\BuildsCampaigns;
use Tests\TestCase;

/**
 * Step C-3.3 — "Plan with AI" runs on the queue because a reasoning model can take longer than a
 * web request may wait (the 60s gateway timeout that surfaced as "Something went wrong"). The
 * request answers 202 with a run id; the planner polls the run until it is done or failed, and
 * only the person who started it can read it.
 */
class CampaignPlanRunTest extends TestCase
{
    use BuildsCampaigns, RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampaignOrg();
        config(['ai.enabled' => true]);
        $this->connect(Platform::Instagram);
        $this->campaign = $this->makeCampaign(['platforms' => ['instagram'], 'start_date' => '2026-11-02', 'end_date' => '2026-11-29']);
    }

    public function test_planning_is_queued_on_the_ai_queue_and_starts_pending(): void
    {
        Queue::fake();

        $run = $this->actingAs($this->strategist())->postJson(route('campaigns.ai.plan', $this->campaign))
            ->assertAccepted()
            ->json('run');

        Queue::assertPushedOn(Queues::AI, PlanCampaignJob::class, fn (PlanCampaignJob $job) => $job->runId === $run && $job->campaignId === $this->campaign->id);

        $this->getJson(route('campaigns.ai.plan.status', [$this->campaign, $run]))
            ->assertOk()
            ->assertExactJson(['status' => 'pending']);
    }

    public function test_a_finished_run_returns_its_suggestions(): void
    {
        $this->fakeAi(fn () => [new AiDeliverableSuggestion('Launch reel', 'instagram', 'reel', null, null, '2026-11-03', '09:00', 'Hello')]);

        $run = $this->actingAs($this->strategist())->postJson(route('campaigns.ai.plan', $this->campaign))->json('run');

        $this->getJson(route('campaigns.ai.plan.status', [$this->campaign, $run]))
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('suggestions.0.title', 'Launch reel')
            ->assertJsonPath('suggestions.0.planned_for', '2026-11-03');

        $this->assertDatabaseCount('campaign_deliverables', 0);
    }

    public function test_a_provider_exception_fails_the_run_with_a_readable_message(): void
    {
        $this->fakeAi(fn () => throw new RuntimeException('DeepSeek exploded'));

        $job = new PlanCampaignJob($this->campaign->id, $this->strategist()->id, $runId = app(CampaignPlanRuns::class)->start($this->campaign, $this->strategist()));

        try {
            $this->app->call([$job, 'handle']);
            $this->fail('Expected the provider exception to bubble up to the worker.');
        } catch (RuntimeException $e) {
            $job->failed($e);
        }

        $this->actingAs($this->strategist())->getJson(route('campaigns.ai.plan.status', [$this->campaign, $runId]))
            ->assertOk()
            ->assertExactJson(['status' => 'failed', 'message' => PlanCampaignJob::FAILED_MESSAGE]);
    }

    public function test_a_platform_lost_before_the_job_runs_fails_the_run_with_the_reason(): void
    {
        $runId = app(CampaignPlanRuns::class)->start($this->campaign, $this->strategist());
        $this->client->socialAccounts()->delete();

        $this->app->call([new PlanCampaignJob($this->campaign->id, $this->strategist()->id, $runId), 'handle']);

        $this->actingAs($this->strategist())->getJson(route('campaigns.ai.plan.status', [$this->campaign, $runId]))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'This client has no connected accounts on this campaign’s platforms. Connect one, then plan.');
    }

    public function test_a_run_is_private_to_the_person_who_started_it_and_unknown_runs_are_404(): void
    {
        Queue::fake();

        $run = $this->actingAs($this->strategist())->postJson(route('campaigns.ai.plan', $this->campaign))->json('run');

        $this->actingAs($this->owner())->getJson(route('campaigns.ai.plan.status', [$this->campaign, $run]))->assertNotFound();
        $this->actingAs($this->strategist())->getJson(route('campaigns.ai.plan.status', [$this->makeCampaign(), $run]))->assertNotFound();
        $this->actingAs($this->strategist())->getJson(route('campaigns.ai.plan.status', [$this->campaign, '00000000-0000-4000-8000-000000000000']))->assertNotFound();
        $this->actingAs($this->designer())->getJson(route('campaigns.ai.plan.status', [$this->campaign, $run]))->assertForbidden();
    }

    /**
     * @param  callable(): list<AiDeliverableSuggestion>  $plan
     */
    private function fakeAi(callable $plan): void
    {
        $this->app->instance(CampaignAiProvider::class, new class($plan) implements CampaignAiProvider
        {
            /** @var callable(): list<AiDeliverableSuggestion> */
            private $plan;

            public function __construct(callable $plan)
            {
                $this->plan = $plan;
            }

            public function draftCampaignBrief(Campaign $campaign): AiCampaignBriefDraft
            {
                throw new \LogicException('Not used.');
            }

            public function planCampaign(Campaign $campaign, array $input): array
            {
                return ($this->plan)();
            }

            public function draftCampaignWrap(Campaign $campaign, array $facts): AiCampaignWrapDraft
            {
                throw new \LogicException('Not used.');
            }
        });
    }
}
