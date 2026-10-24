<?php

namespace App\Contracts;

use App\Models\Client;
use App\Models\InboxConversation;

/**
 * Step M-9.3 — drafts an on-brand reply to a social-inbox comment, grounded in the client's Brand
 * Brain. Returns the suggested reply text only (the Action budgets, meters and guardrails it);
 * never persists and never sends — a human always presses Send. Bound to DeepSeek when AI is
 * enabled and keyed, otherwise a Null implementation that returns an empty string.
 */
interface ReplyAssistant
{
    /**
     * @param  string|null  $instruction  optional steer from the user ("offer a discount code", "keep it short")
     */
    public function draftReply(Client $client, InboxConversation $conversation, ?string $instruction = null): string;
}
