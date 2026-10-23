<?php

namespace App\Services\AI;

use App\Actions\AI\RecordAiUsageAction;
use App\Models\Client;
use App\Models\User;
use App\Support\AI\AiUsage;

/**
 * Step M-7.1 — meters one agency text AI call (captions, trends, ideas, writing tools) onto
 * `ai_usage_logs`, reading the real token counts from {@see DeepSeekProvider::lastUsage()} when the
 * resolved provider is DeepSeek (the Null provider makes no vendor call, so it logs a zero-cost,
 * zero-token row that still counts the attempt). Keeps the instanceof/token plumbing in one place so
 * every Action records usage the same way.
 */
class AiCallMeter
{
    public function __construct(private readonly RecordAiUsageAction $record) {}

    public function record(object $provider, Client $client, ?User $user, string $method, bool $succeeded): void
    {
        $isDeepSeek = $provider instanceof DeepSeekProvider;
        $usage = $isDeepSeek ? $provider->lastUsage() : null;

        ($this->record)(new AiUsage(
            orgId: $client->org_id,
            clientId: $client->id,
            userId: $user?->id,
            provider: $isDeepSeek ? 'deepseek' : 'null',
            model: $isDeepSeek ? (string) config('ai.deepseek.model', 'deepseek-flash') : 'none',
            method: $method,
            succeeded: $succeeded,
            errorCode: $succeeded ? null : 'empty_response',
            costEstimate: $isDeepSeek && $succeeded ? (float) config('ai.deepseek.estimated_cost_per_call', 0) : 0.0,
            durationMs: $usage['duration_ms'] ?? null,
            promptTokens: $usage['prompt_tokens'] ?? null,
            completionTokens: $usage['completion_tokens'] ?? null,
        ));
    }
}
