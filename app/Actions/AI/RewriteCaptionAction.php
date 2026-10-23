<?php

namespace App\Actions\AI;

use App\Contracts\WritingAssistant;
use App\Enums\Platform;
use App\Enums\WritingTool;
use App\Models\Client;
use App\Services\AI\AiCallMeter;
use App\Services\AI\AiGuardrailService;
use App\Services\AI\AiUsageGuard;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Step M-7.3 (dev plan I-9.4) — one editor writing tool (shorten, change tone, adapt to a platform)
 * applied to a caption. Budget-gated and metered like every agency text call; the rewrite runs
 * through the same banned-phrase guardrail as caption suggestions (a rewrite that introduces a
 * do-not phrase is dropped and the original returned), then clamped to the target platform's caption
 * limit. Returns text for the editor to drop in — never saved automatically.
 */
class RewriteCaptionAction
{
    public function __construct(
        private readonly WritingAssistant $assistant,
        private readonly AiGuardrailService $guardrail,
        private readonly AiUsageGuard $budget = new AiUsageGuard,
        private readonly AiCallMeter $meter = new AiCallMeter(new RecordAiUsageAction),
    ) {}

    public function __invoke(Client $client, string $text, WritingTool $tool, ?Platform $platform = null, ?string $tone = null): string
    {
        Log::info('AI caption rewrite requested', [
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'tool' => $tool->value,
            'platform' => $platform?->value,
        ]);

        $this->budget->ensureWithinBudget($client->organization);

        $rewritten = $this->assistant->rewrite($client, $text, $tool, $platform, $tone);
        $this->meter->record($this->assistant, $client, Auth::user(), 'rewrite', $rewritten !== $text);

        // Reuse the caption guardrail: a rewrite that introduces a banned phrase is dropped (we fall
        // back to the original), surviving text is PII-scrubbed — the same treatment AI suggestions get.
        $filtered = $this->guardrail->filterCaptionSuggestions(
            $client->brandBrain,
            new AiCaptionSuggestions([new AiCaptionSuggestion($rewritten, [], null, null)]),
            ['org_id' => $client->org_id, 'client_id' => $client->id, 'tool' => $tool->value],
        )->suggestions[0] ?? null;

        $result = $filtered !== null && trim($filtered->caption) !== '' ? $filtered->caption : $text;

        if ($platform !== null) {
            $result = mb_substr($result, 0, $platform->captionLimit());
        }

        return $result;
    }
}
