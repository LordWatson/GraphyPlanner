<?php

namespace App\Actions\AI\Images;

use App\Enums\ImageGenerationStatus;
use App\Enums\ImageVariantStatus;
use App\Models\AiImageGeneration;
use Illuminate\Support\Facades\Log;

/**
 * Derives a set's status from its (non-discarded) variants (Step 16.7): anything pending →
 * generating (queued until the brief exists), all ready → ready, all failed → failed, a mix →
 * partially_failed. Runs after every variant and in the batch's `finally`.
 */
class RecomputeImageGenerationStatusAction
{
    public function __invoke(AiImageGeneration $generation): ImageGenerationStatus
    {
        $statuses = $generation->variants()
            ->whereNull('discarded_at')
            ->pluck('status')
            ->map(fn ($status) => $status instanceof ImageVariantStatus ? $status : ImageVariantStatus::from((string) $status));

        $status = match (true) {
            $statuses->isEmpty() => ImageGenerationStatus::Ready,
            $statuses->contains(fn (ImageVariantStatus $s) => $s->isPending()) => $generation->brief === null
                ? ImageGenerationStatus::Queued
                : ImageGenerationStatus::Generating,
            $statuses->every(fn (ImageVariantStatus $s) => $s === ImageVariantStatus::Ready) => ImageGenerationStatus::Ready,
            $statuses->every(fn (ImageVariantStatus $s) => $s === ImageVariantStatus::Failed) => ImageGenerationStatus::Failed,
            default => ImageGenerationStatus::PartiallyFailed,
        };

        if ($generation->status !== $status) {
            $generation->update(['status' => $status]);

            if ($status->isSettled()) {
                Log::info('AI image set settled', [
                    'org_id' => $generation->org_id,
                    'client_id' => $generation->client_id,
                    'generation_id' => $generation->id,
                    'status' => $status->value,
                ]);
            }
        }

        return $status;
    }
}
