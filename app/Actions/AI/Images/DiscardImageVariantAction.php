<?php

namespace App\Actions\AI\Images;

use App\Models\AiImageVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hide (or restore) a tile in the studio (Step 16.9). Discarded tiles are hidden by default and
 * their files are pruned after `ai.images.prune_after_days` unless attached to a post (Step 16.13).
 */
class DiscardImageVariantAction
{
    public function __construct(private readonly RecomputeImageGenerationStatusAction $recompute) {}

    public function __invoke(AiImageVariant $variant, User $user, bool $discarded = true): void
    {
        DB::transaction(function () use ($variant, $discarded) {
            $variant->update(['discarded_at' => $discarded ? now() : null]);
            ($this->recompute)($variant->generation);
        });

        Log::info($discarded ? 'AI image variant discarded' : 'AI image variant restored', [
            'org_id' => $variant->org_id,
            'generation_id' => $variant->generation_id,
            'variant_id' => $variant->id,
            'user_id' => $user->id,
        ]);
    }
}
