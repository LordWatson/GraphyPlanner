<?php

namespace App\Actions\Inbox;

use App\Actions\AI\RecordAiUsageAction;
use App\Contracts\ReplyAssistant;
use App\Models\InboxConversation;
use App\Services\AI\AiCallMeter;
use App\Services\AI\AiGuardrailService;
use App\Services\AI\AiUsageGuard;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Step M-9.3 — draft an on-brand reply to an inbox conversation. Budget-gated and metered like every
 * agency text call, and run through the same banned-phrase guardrail as caption suggestions: a draft
 * that introduces a Brand Brain "never use" / "do not" phrase is dropped and an empty string
 * returned, so the UI falls back to "write your own". Returns text only — it never persists and
 * never sends. Sending is a separate, explicit human action ({@see SendInboxReplyAction}).
 */
class DraftInboxReplyAction
{
    public function __construct(
        private readonly ReplyAssistant $assistant,
        private readonly AiGuardrailService $guardrail,
        private readonly AiUsageGuard $budget = new AiUsageGuard,
        private readonly AiCallMeter $meter = new AiCallMeter(new RecordAiUsageAction),
    ) {}

    public function __invoke(InboxConversation $conversation, ?string $instruction = null): string
    {
        $client = $conversation->client;

        if ($client === null) {
            return '';
        }

        Log::info('AI inbox reply draft requested', [
            'org_id' => $conversation->org_id,
            'client_id' => $client->id,
            'inbox_conversation_id' => $conversation->id,
        ]);

        $this->budget->ensureWithinBudget($client->organization);

        $draft = $this->assistant->draftReply($client, $conversation, $instruction);
        $this->meter->record($this->assistant, $client, Auth::user(), 'inbox_reply', trim($draft) !== '');

        if (trim($draft) === '') {
            return '';
        }

        // Reuse the caption guardrail: a draft with a banned phrase is dropped, survivors PII-scrubbed.
        $filtered = $this->guardrail->filterCaptionSuggestions(
            $client->brandBrain,
            new AiCaptionSuggestions([new AiCaptionSuggestion($draft, [], null, null)]),
            ['org_id' => $conversation->org_id, 'client_id' => $client->id, 'inbox_conversation_id' => $conversation->id],
        )->suggestions[0] ?? null;

        return $filtered !== null ? trim($filtered->caption) : '';
    }
}
