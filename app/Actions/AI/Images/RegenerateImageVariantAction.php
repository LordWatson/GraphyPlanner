<?php

namespace App\Actions\AI\Images;

use App\Enums\ImageQualityTier;
use App\Enums\ImageVariantKind;
use App\Models\AiImageVariant;
use App\Models\User;

/**
 * Step 16.8: same brief, new seed. Replaces a failed or unloved tile (the old one is discarded).
 */
class RegenerateImageVariantAction
{
    public function __construct(private readonly CreateFollowUpVariantAction $followUp) {}

    public function __invoke(AiImageVariant $variant, User $user, string $requestId): AiImageVariant
    {
        return ($this->followUp)($variant, $user, ImageVariantKind::Regenerate, $requestId, ImageQualityTier::Draft);
    }
}
