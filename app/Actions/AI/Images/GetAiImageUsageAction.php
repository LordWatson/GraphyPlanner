<?php

namespace App\Actions\AI\Images;

use App\Enums\ImageQualityTier;
use App\Enums\ImageVariantStatus;
use App\Models\AiImageVariant;
use App\Models\AiUsageLog;
use App\Models\Organization;
use App\Services\AI\Images\ImageQuotaGuard;
use Carbon\CarbonImmutable;

/**
 * Step 16.11: this calendar month's Image Studio usage for Settings → AI usage (Owner only):
 * images counted against the quota, how many were finals, and the estimated vendor cost from
 * `ai_usage_logs`.
 */
class GetAiImageUsageAction
{
    public function __construct(private readonly ImageQuotaGuard $quota) {}

    /**
     * @return array{month: string, used: int, quota: int|null, remaining: int|null, finals: int, cost_estimate: float, failed: int}
     */
    public function __invoke(Organization $organization, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $start = $now->startOfMonth();
        $end = $start->addMonth();

        $summary = $this->quota->summary($organization);

        $finals = AiImageVariant::query()
            ->where('org_id', $organization->id)
            ->where('quality_tier', ImageQualityTier::Final->value)
            ->where('status', '!=', ImageVariantStatus::Failed->value)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $logs = AiUsageLog::query()
            ->where('org_id', $organization->id)
            ->where('method', 'like', 'image.%')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end);

        return [
            'month' => $start->format('F Y'),
            'used' => $summary['used'],
            'quota' => $summary['quota'],
            'remaining' => $summary['remaining'],
            'finals' => $finals,
            'cost_estimate' => round((float) (clone $logs)->sum('cost_estimate'), 2),
            'failed' => (clone $logs)->where('succeeded', false)->count(),
        ];
    }
}
