<?php

namespace App\Services\AI;

use App\Models\AiUsageLog;
use App\Models\Client;
use App\Services\Campaigns\CampaignAiUsage;
use Illuminate\Support\Facades\Auth;

/**
 * Step B-6.1/B-6.2 — one `ai_usage_logs` row per client-scoped text/vision AI call (idea generation,
 * caption-from-photo), the counterpart of the campaign recorder ({@see CampaignAiUsage}) for the
 * business shell. The caller passes the resolved provider/model/cost (0 for the Null provider, i.e.
 * AI off). Never stores the prompt or the response — just who called what, whether it worked, and
 * how long it took. The row is what the B-9.2 AI allowance will meter against.
 */
class AiTextUsage
{
    public function record(Client $client, string $method, bool $succeeded, float $startedAt, string $provider, string $model, float $cost = 0.0): void
    {
        AiUsageLog::create([
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'user_id' => Auth::id(),
            'provider' => $provider,
            'model' => $model,
            'method' => $method,
            'succeeded' => $succeeded,
            'error_code' => $succeeded ? null : 'empty_response',
            'cost_estimate' => $succeeded ? $cost : 0,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
