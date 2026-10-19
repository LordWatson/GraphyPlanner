<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\User;
use App\Support\AI\Campaigns\AiDeliverableSuggestion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Step C-3.3 — the state of one "Plan with AI" run while `PlanCampaignJob` works on the queue. A
 * reasoning model can take over a minute to plan a campaign, longer than a web request is allowed
 * to wait (nginx/PHP-FPM cut it off at 60s with an HTML 504), so the request only starts a run and
 * the planner polls for the outcome. Runs live in the cache for an hour, keyed by campaign and user
 * so nobody else can read them; nothing here is a deliverable until a person accepts it.
 */
class CampaignPlanRuns
{
    public const PENDING = 'pending';

    public const DONE = 'done';

    public const FAILED = 'failed';

    private const TTL_SECONDS = 3600;

    public function start(Campaign $campaign, User $user): string
    {
        $runId = (string) Str::uuid();

        Cache::put($this->key($campaign->id, $user->id, $runId), ['status' => self::PENDING], self::TTL_SECONDS);

        return $runId;
    }

    /**
     * @param  list<AiDeliverableSuggestion>  $suggestions
     */
    public function complete(int $campaignId, int $userId, string $runId, array $suggestions): void
    {
        Cache::put($this->key($campaignId, $userId, $runId), [
            'status' => self::DONE,
            'suggestions' => array_map(fn (AiDeliverableSuggestion $suggestion) => $suggestion->jsonSerialize(), $suggestions),
        ], self::TTL_SECONDS);
    }

    public function fail(int $campaignId, int $userId, string $runId, string $message): void
    {
        Cache::put($this->key($campaignId, $userId, $runId), ['status' => self::FAILED, 'message' => $message], self::TTL_SECONDS);
    }

    /**
     * The run as stored — `status`, plus `suggestions` once done or `message` once failed — ready
     * to send to the planner as JSON. Null when it never existed, expired, or isn't this user's.
     *
     * @return array<mixed>|null
     */
    public function find(Campaign $campaign, User $user, string $runId): ?array
    {
        $run = Cache::get($this->key($campaign->id, $user->id, $runId));

        return is_array($run) && is_string($run['status'] ?? null) ? $run : null;
    }

    private function key(int $campaignId, int $userId, string $runId): string
    {
        return "campaign-plan-run:{$campaignId}:{$userId}:{$runId}";
    }
}
