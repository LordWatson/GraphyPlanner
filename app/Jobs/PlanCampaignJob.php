<?php

namespace App\Jobs;

use App\Actions\Campaigns\PlanCampaignAction;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Campaigns\CampaignPlanRuns;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Step C-3.3 — runs the AI campaign planner off the request, on the `ai` queue, and stores the
 * outcome on the run (`CampaignPlanRuns`) for the planner to poll. Never retried: a second paid
 * call would only repeat a minute-long wait, so a failure is shown and the person can try again.
 */
class PlanCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const FAILED_MESSAGE = 'The AI couldn’t finish this plan. Try again in a moment.';

    public int $tries = 1;

    /** Below the Horizon `ai` supervisor's 150s; DeepSeek planning alone can take 60–90s. */
    public int $timeout = 140;

    public function __construct(
        public readonly int $campaignId,
        public readonly int $userId,
        public readonly string $runId,
    ) {
        $this->onQueue(Queues::AI);
    }

    public function handle(PlanCampaignAction $action, CampaignPlanRuns $runs): void
    {
        $context = ['campaign_id' => $this->campaignId, 'user_id' => $this->userId, 'run_id' => $this->runId];
        $campaign = Campaign::query()->withoutOrgScope()->find($this->campaignId);
        $user = User::query()->find($this->userId);

        if ($campaign === null || $user === null) {
            Log::warning('PlanCampaignJob: campaign or user not found', $context);
            $runs->fail($this->campaignId, $this->userId, $this->runId, 'This campaign is no longer available.');

            return;
        }

        Log::info('PlanCampaignJob started', $context);

        try {
            $suggestions = $action($campaign, $user);
        } catch (ValidationException $e) {
            Log::warning('PlanCampaignJob refused', [...$context, 'reason' => $e->getMessage()]);
            $runs->fail($this->campaignId, $this->userId, $this->runId, $e->getMessage());

            return;
        }

        $runs->complete($this->campaignId, $this->userId, $this->runId, $suggestions);

        Log::info('PlanCampaignJob completed', [...$context, 'suggestions' => count($suggestions)]);
    }

    /**
     * Any other exception, or the worker killing the job at its timeout, lands here.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('PlanCampaignJob failed', [
            'campaign_id' => $this->campaignId,
            'user_id' => $this->userId,
            'run_id' => $this->runId,
            'exception' => $exception !== null ? $exception::class : null,
            'error' => $exception?->getMessage(),
        ]);

        app(CampaignPlanRuns::class)->fail($this->campaignId, $this->userId, $this->runId, self::FAILED_MESSAGE);
    }
}
