<?php

namespace App\Actions\AI;

use App\Models\AiUsageLog;
use App\Support\AI\AiUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step M-7.1 (dev plan Step 2.8) — the single write path for an `ai_usage_logs` row on the agency
 * text/AI path (captions, trends, ideas, writing tools). Wrapped in `DB::transaction()`, and it
 * **never** throws: usage logging is bookkeeping, so if the insert fails we log a warning and let
 * the user-facing request carry on rather than turning a working AI answer into an error.
 */
class RecordAiUsageAction
{
    public function __invoke(AiUsage $usage): void
    {
        try {
            DB::transaction(fn () => AiUsageLog::create([
                'org_id' => $usage->orgId,
                'client_id' => $usage->clientId,
                'campaign_id' => $usage->campaignId,
                'user_id' => $usage->userId,
                'provider' => $usage->provider,
                'model' => $usage->model,
                'method' => $usage->method,
                'succeeded' => $usage->succeeded,
                'error_code' => $usage->succeeded ? null : $usage->errorCode,
                'cost_estimate' => $usage->succeeded ? $usage->costEstimate : 0,
                'duration_ms' => $usage->durationMs,
                'prompt_tokens' => $usage->promptTokens,
                'completion_tokens' => $usage->completionTokens,
            ]));
        } catch (Throwable $e) {
            Log::warning('Failed to record AI usage', [
                'org_id' => $usage->orgId,
                'client_id' => $usage->clientId,
                'method' => $usage->method,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
