<?php

namespace App\Exceptions\AI;

use App\Services\AI\AiUsageGuard;
use RuntimeException;

/**
 * Step M-7.1 (dev plan Step 2.8) — thrown by {@see AiUsageGuard} when an org has
 * used up its configured monthly AI token allowance. Carries a plain, non-technical message so the
 * UI can show it verbatim instead of a generic 500.
 */
class AiBudgetExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $budget,
        public readonly int $used,
        string $message = 'Monthly AI limit reached — contact your admin.',
    ) {
        parent::__construct($message);
    }
}
