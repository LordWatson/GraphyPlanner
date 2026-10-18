<?php

namespace App\Services\Campaigns;

use App\Contracts\CampaignAiProvider;
use App\Models\AiUsageLog;
use App\Models\Campaign;
use App\Models\User;
use App\Services\AI\DeepSeekProvider;

/**
 * Campaigns enhancement — one `ai_usage_logs` row per campaign AI call (C-2.2, C-3.3, C-7.3), with
 * the campaign id so profitability (C-8.3) can sum AI cost per campaign. Text calls are estimated
 * at zero cost (the vendor bills per token and the app doesn't meter tokens yet), so the row is a
 * call count with success/failure. Never stores the prompt or the response.
 */
class CampaignAiUsage
{
    public function record(Campaign $campaign, ?User $user, CampaignAiProvider $provider, string $method, bool $succeeded, float $startedAt): void
    {
        $isDeepSeek = $provider instanceof DeepSeekProvider;

        AiUsageLog::create([
            'org_id' => $campaign->org_id,
            'client_id' => $campaign->client_id,
            'campaign_id' => $campaign->id,
            'user_id' => $user?->id,
            'provider' => $isDeepSeek ? 'deepseek' : 'null',
            'model' => $isDeepSeek ? (string) config('ai.deepseek.model', 'deepseek-chat') : 'none',
            'method' => $method,
            'succeeded' => $succeeded,
            'error_code' => $succeeded ? null : 'empty_response',
            'cost_estimate' => $isDeepSeek && $succeeded ? (float) config('ai.deepseek.estimated_cost_per_call', 0) : 0,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
