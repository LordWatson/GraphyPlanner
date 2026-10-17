<?php

namespace App\Actions\AI\Images;

use App\Enums\ImageErrorCode;
use App\Enums\ImageGenerationStatus;
use App\Enums\ImageVariantStatus;
use App\Jobs\GenerateImageVariantJob;
use App\Models\AiImageGeneration;
use App\Models\AiImageVariant;
use App\Support\AI\Images\ImageBriefInputResolver;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step 16.7, on the `ai-images` queue: drafts (or fetches the cached) art-direction brief for a
 * set once, stores it on the generation, then fans the set's pending variants out as one Bus
 * batch of GenerateImageVariantJobs. The batch's `finally` recomputes the set status.
 */
class PrepareImageGenerationAction
{
    public function __construct(
        private readonly DraftImageBriefAction $draftBrief,
        private readonly ImageBriefInputResolver $inputs,
        private readonly RecomputeImageGenerationStatusAction $recompute,
    ) {}

    public function __invoke(AiImageGeneration $generation): void
    {
        $context = ['org_id' => $generation->org_id, 'client_id' => $generation->client_id, 'generation_id' => $generation->id];

        if ($generation->brief === null) {
            try {
                $result = ($this->draftBrief)($generation->client, $this->inputs->forGeneration($generation));
            } catch (Throwable $e) {
                Log::error('AI image set brief could not be drafted', [...$context, 'exception' => $e::class]);

                $generation->variants()
                    ->whereIn('status', [ImageVariantStatus::Queued->value, ImageVariantStatus::Generating->value])
                    ->update(['status' => ImageVariantStatus::Failed->value, 'error_code' => ImageErrorCode::ProviderError->value]);

                ($this->recompute)($generation);

                return;
            }

            $generation->update([
                'brief' => $result['brief']->toArray(),
                'brief_hash' => $result['hash'],
                'status' => ImageGenerationStatus::Generating,
            ]);
        }

        $pending = $generation->variants()
            ->where('status', ImageVariantStatus::Queued->value)
            ->whereNull('asset_id')
            ->pluck('id');

        if ($pending->isEmpty()) {
            ($this->recompute)($generation);

            return;
        }

        $generationId = $generation->id;

        Bus::batch($pending->map(fn (int $variantId) => new GenerateImageVariantJob($variantId))->all())
            ->name("ai-image-set:{$generationId}")
            ->onQueue((string) config('ai.images.queue', 'ai-images'))
            ->allowFailures()
            ->finally(static function (Batch $batch) use ($generationId): void {
                $generation = AiImageGeneration::find($generationId);

                if ($generation !== null) {
                    app(RecomputeImageGenerationStatusAction::class)($generation);
                }
            })
            ->dispatch();

        Log::info('AI image set dispatched', [...$context, 'variants' => $pending->count()]);
    }

    /**
     * Single-variant follow-ups (refine, regenerate, make final) skip the batch: one job.
     */
    public function dispatchVariant(AiImageVariant $variant): void
    {
        GenerateImageVariantJob::dispatch($variant->id);
    }
}
