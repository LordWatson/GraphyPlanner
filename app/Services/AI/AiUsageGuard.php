<?php

namespace App\Services\AI;

use App\Exceptions\AI\AiBudgetExceededException;
use App\Models\AiUsageLog;
use App\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Step M-7.1 (dev plan Step 2.8) — the per-org monthly token allowance for the agency text AI path.
 *
 * `organizations.ai_monthly_token_budget` is the ceiling (null = unlimited). The guard sums the
 * prompt+completion tokens recorded on `ai_usage_logs` this calendar month (image generations carry
 * no tokens, so they don't count against the text budget) and refuses a new call once the ceiling is
 * reached, throwing {@see AiBudgetExceededException} for the Action to surface as a clean message.
 *
 * The budget is checked *before* dispatching a request. Because the preceding call's tokens are only
 * known after it returns, a single request can tip usage slightly past the ceiling; that's accepted
 * (the ceiling is an allowance, not a hard billing cap) and keeps the check cheap and predictable.
 */
class AiUsageGuard
{
    /**
     * Throw if the org has no remaining budget. A null budget means unlimited.
     */
    public function ensureWithinBudget(?Organization $organization, ?CarbonImmutable $now = null): void
    {
        $budget = $organization?->ai_monthly_token_budget;

        if ($organization === null || $budget === null) {
            return;
        }

        $used = $this->tokensUsedThisMonth($organization, $now);

        if ($used >= $budget) {
            throw new AiBudgetExceededException(budget: $budget, used: $used);
        }
    }

    /**
     * Prompt+completion tokens recorded for this org in the current calendar month.
     */
    public function tokensUsedThisMonth(Organization $organization, ?CarbonImmutable $now = null): int
    {
        $start = ($now ?? CarbonImmutable::now())->startOfMonth();

        return (int) AiUsageLog::query()
            ->where('org_id', $organization->id)
            ->where('created_at', '>=', $start)
            ->selectRaw('COALESCE(SUM(prompt_tokens), 0) + COALESCE(SUM(completion_tokens), 0) as total')
            ->value('total');
    }

    /**
     * Current-month usage summary for the Settings → AI usage panel.
     *
     * @return array{budget: int|null, used: int, remaining: int|null, requests: int, cost_estimate: float}
     */
    public function summary(Organization $organization, ?CarbonImmutable $now = null): array
    {
        $start = ($now ?? CarbonImmutable::now())->startOfMonth();
        $budget = $organization->ai_monthly_token_budget;
        $used = $this->tokensUsedThisMonth($organization, $now);

        $textLogs = AiUsageLog::query()
            ->where('org_id', $organization->id)
            ->where('created_at', '>=', $start)
            ->where('method', 'not like', 'image.%');

        return [
            'budget' => $budget,
            'used' => $used,
            'remaining' => $budget === null ? null : max(0, $budget - $used),
            'requests' => (clone $textLogs)->count(),
            'cost_estimate' => round((float) (clone $textLogs)->sum('cost_estimate'), 2),
        ];
    }
}
