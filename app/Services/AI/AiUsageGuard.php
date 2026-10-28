<?php

namespace App\Services\AI;

use App\Contracts\Entitlements;
use App\Enums\Feature;
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
        if ($organization === null) {
            return;
        }

        // M-7.1: the optional per-org token ceiling (an explicit owner/super-admin override).
        $budget = $organization->ai_monthly_token_budget;

        if ($budget !== null) {
            $used = $this->tokensUsedThisMonth($organization, $now);

            if ($used >= $budget) {
                throw new AiBudgetExceededException(budget: $budget, used: $used);
            }
        }

        // CG-2.3 / B-9.2: the plan's monthly AI text-credit allowance (one credit = one text request),
        // plus any unexpired super-admin bonus grant. Null = unlimited (agency plans).
        $this->ensureWithinPlanTextCredits($organization, $now);
    }

    /**
     * Enforce the plan's `ai_text_credits` allowance (request count this month), including any
     * unexpired bonus grant. Unlimited plans (null limit) are never blocked.
     */
    public function ensureWithinPlanTextCredits(Organization $organization, ?CarbonImmutable $now = null): void
    {
        $allowance = $this->textCreditAllowance($organization);

        if ($allowance === null) {
            return;
        }

        $used = $this->textRequestsThisMonth($organization, $now);

        if ($used >= $allowance) {
            throw new AiBudgetExceededException(budget: $allowance, used: $used);
        }
    }

    /**
     * The plan's AI text-credit limit plus any unexpired bonus grant (null = unlimited).
     */
    public function textCreditAllowance(Organization $organization): ?int
    {
        $limit = app(Entitlements::class)->limit($organization, Feature::AiTextCredits);

        if ($limit === null) {
            return null;
        }

        return $limit + $organization->bonusAiTextCredits();
    }

    /**
     * Count of AI text requests (excludes image generations) recorded this calendar month.
     */
    public function textRequestsThisMonth(Organization $organization, ?CarbonImmutable $now = null): int
    {
        $start = ($now ?? CarbonImmutable::now())->startOfMonth();

        return (int) AiUsageLog::query()
            ->where('org_id', $organization->id)
            ->where('created_at', '>=', $start)
            ->where('method', 'not like', 'image.%')
            ->count();
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
     * Current-month usage summary for the Settings → AI usage / plan pages.
     *
     * @return array{budget: int|null, used: int, remaining: int|null, requests: int, cost_estimate: float, credits: array{allowance: int|null, used: int, remaining: int|null, bonus: int}}
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

        $requests = (clone $textLogs)->count();
        $allowance = $this->textCreditAllowance($organization);

        return [
            'budget' => $budget,
            'used' => $used,
            'remaining' => $budget === null ? null : max(0, $budget - $used),
            'requests' => $requests,
            'cost_estimate' => round((float) (clone $textLogs)->sum('cost_estimate'), 2),
            'credits' => [
                'allowance' => $allowance,
                'used' => $requests,
                'remaining' => $allowance === null ? null : max(0, $allowance - $requests),
                'bonus' => $organization->bonusAiTextCredits(),
            ],
        ];
    }
}
