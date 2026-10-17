<?php

namespace App\Actions\AI\Images;

use App\Enums\ImageQualityTier;
use App\Enums\ImageVariantKind;
use App\Enums\ImageVariantStatus;
use App\Models\AiImageVariant;
use App\Models\User;
use InvalidArgumentException;

/**
 * Step 16.8 "Make final": re-renders a chosen draft at the final tier (Nano Banana Pro) with the
 * draft as the lead reference, so the composition people picked is kept. The draft is marked
 * `selected_at`. Only drafts can be finalised, and a draft is finalised at most once (an existing,
 * non-failed final child is returned instead), so Pro spend tracks real picks.
 */
class FinalizeImageVariantAction
{
    public function __construct(private readonly CreateFollowUpVariantAction $followUp) {}

    public function __invoke(AiImageVariant $variant, User $user, string $requestId): AiImageVariant
    {
        if ($variant->quality_tier === ImageQualityTier::Final) {
            throw new InvalidArgumentException('This image is already final.');
        }

        $existingFinal = $variant->children()
            ->where('kind', ImageVariantKind::Final->value)
            ->where('status', '!=', ImageVariantStatus::Failed->value)
            ->first();

        if ($existingFinal !== null) {
            return $existingFinal;
        }

        $final = ($this->followUp)($variant, $user, ImageVariantKind::Final, $requestId, ImageQualityTier::Final, [
            'selected_at' => now(),
        ]);

        $variant->update(['selected_at' => $variant->selected_at ?? now()]);

        return $final;
    }
}
