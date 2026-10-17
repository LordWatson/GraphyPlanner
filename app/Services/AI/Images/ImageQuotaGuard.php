<?php

namespace App\Services\AI\Images;

use App\Enums\ImageVariantStatus;
use App\Exceptions\AI\AiImageQuotaExceededException;
use App\Models\AiImageVariant;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Monthly image quota per organization (Step 16.11). Counts every variant created this calendar
 * month (drafts, finals and refinements each count as one) except ones that failed, since a
 * failed render is never billed. `organizations.ai_monthly_image_quota` null = unlimited.
 */
class ImageQuotaGuard
{
    /**
     * @throws AiImageQuotaExceededException
     */
    public function assertCanGenerate(Organization $organization, int $images): void
    {
        $quota = $organization->ai_monthly_image_quota;

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
        $quota = $organization->ai_monthly_image_quota;

        return [
            'used' => $used,
            'quota' => $quota,
            'remaining' => $quota === null ? null : max(0, $quota - $used),
        ];
    }
}
