<?php

namespace App\Services\AI\Images;

use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Enums\ImageVariantStatus;
use App\Exceptions\AI\AiImageQuotaExceededException;
use App\Models\AiImageVariant;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Monthly image quota per organization (Step 16.11). Counts every variant created this calendar
 * month (drafts, finals and refinements each count as one) except ones that failed, since a
 * failed render is never billed.
 *
 * Step CG-2.3 / B-9.2 — the effective quota is the org's explicit `ai_monthly_image_quota` if set,
 * otherwise the plan's `ai_image_credits` limit (null = unlimited). So the plan sets a sensible
 * default and a super admin / owner override still wins.
 */
class ImageQuotaGuard
{
    /**
     * @throws AiImageQuotaExceededException
     */
    public function assertCanGenerate(Organization $organization, int $images): void
    {
        $quota = $this->effectiveQuota($organization);

        if ($quota === null) {
            return;
        }

        $used = $this->usedThisMonth($organization);

        if ($used + $images > $quota) {
            Log::warning('AI image quota exceeded', [
                'org_id' => $organization->id,
                'quota' => $quota,
                'used' => $used,
                'requested' => $images,
            ]);

            throw new AiImageQuotaExceededException;
        }
    }

    public function usedThisMonth(Organization $organization, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        return AiImageVariant::query()
            ->where('org_id', $organization->id)
            ->where('created_at', '>=', $now->startOfMonth())
            ->where('created_at', '<', $now->startOfMonth()->addMonth())
            ->where('status', '!=', ImageVariantStatus::Failed->value)
            ->count();
    }

    /**
     * @return array{used: int, quota: int|null, remaining: int|null}
     */
    public function summary(Organization $organization): array
    {
        $used = $this->usedThisMonth($organization);
        $quota = $this->effectiveQuota($organization);

        return [
            'used' => $used,
            'quota' => $quota,
            'remaining' => $quota === null ? null : max(0, $quota - $used),
        ];
    }

    /**
     * The org's explicit image quota, else the plan's `ai_image_credits` limit (null = unlimited).
     */
    public function effectiveQuota(Organization $organization): ?int
    {
        if ($organization->ai_monthly_image_quota !== null) {
            return $organization->ai_monthly_image_quota;
        }

        return app(Entitlements::class)->limit($organization, Feature::AiImageCredits);
    }
}
