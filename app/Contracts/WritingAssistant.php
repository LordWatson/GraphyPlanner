<?php

namespace App\Contracts;

use App\Enums\Platform;
use App\Enums\WritingTool;
use App\Models\Client;

/**
 * Step M-7.3 (dev plan I-9.4) — rewrites a piece of caption text with one of the editor's writing
 * tools, grounded in the client's Brand Brain. Returns the rewritten text only (the Action
 * guardrails and length-clamps it); never persists anything. Bound to DeepSeek when AI is enabled
 * and keyed, otherwise a Null implementation that returns the text unchanged.
 */
interface WritingAssistant
{
    public function rewrite(Client $client, string $text, WritingTool $tool, ?Platform $platform = null, ?string $tone = null): string;
}
