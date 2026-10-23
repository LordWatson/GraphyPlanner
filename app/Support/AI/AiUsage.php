<?php

namespace App\Support\AI;

use App\Actions\AI\RecordAiUsageAction;

/**
 * Step M-7.1 — one AI vendor call's bookkeeping, passed to {@see RecordAiUsageAction}.
 * Deliberately carries no prompt or response text — only who called what, whether it worked, how
 * long it took and how many tokens it cost, so an org's monthly allowance can be metered.
 */
readonly class AiUsage
{
    public function __construct(
        public int $orgId,
        public ?int $clientId,
        public ?int $userId,
        public string $provider,
        public string $model,
        public string $method,
        public bool $succeeded,
        public ?string $errorCode = null,
        public float $costEstimate = 0.0,
        public ?int $durationMs = null,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        public ?int $campaignId = null,
    ) {}

    /**
     * Tokens this call cost, summing prompt and completion. Null when the vendor reported neither
     * (e.g. a failure before any response, or the Null provider while AI is off).
     */
    public function totalTokens(): ?int
    {
        if ($this->promptTokens === null && $this->completionTokens === null) {
            return null;
        }

        return ($this->promptTokens ?? 0) + ($this->completionTokens ?? 0);
    }
}
