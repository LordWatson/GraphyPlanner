<?php

namespace App\Jobs;

use App\Actions\AI\Images\RecomputeImageGenerationStatusAction;
use App\Actions\AI\Images\RenderImageVariantAction;
use App\Enums\ImageErrorCode;
use App\Enums\ImageVariantStatus;
use App\Models\AiImageVariant;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step 16.7: renders one Image Studio variant on the `ai-images` queue. Unique per variant and
 * idempotent inside RenderImageVariantAction (a variant with an asset is skipped), so a retry or
 * a duplicate dispatch never pays the vendor twice.
 */
class GenerateImageVariantJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $variantId)
    {
        $this->onQueue((string) config('ai.images.queue', 'ai-images'));
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'variant:'.$this->variantId;
    }

    public function handle(RenderImageVariantAction $render): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $variant = AiImageVariant::find($this->variantId);

        if ($variant === null) {
            Log::warning('GenerateImageVariantJob: variant not found', ['variant_id' => $this->variantId]);

            return;
        }

        $render($variant);
    }

    /**
     * Unexpected crash after all tries (provider errors are handled inside the action): never
     * leave a tile spinning forever.
     */
    public function failed(?Throwable $exception): void
    {
        $variant = AiImageVariant::find($this->variantId);

        if ($variant === null || $variant->asset_id !== null) {
            return;
        }

        $variant->update(['status' => ImageVariantStatus::Failed, 'error_code' => ImageErrorCode::ProviderError]);

        Log::error('GenerateImageVariantJob failed', [
            'variant_id' => $variant->id,
            'generation_id' => $variant->generation_id,
            'org_id' => $variant->org_id,
            'exception' => $exception ? $exception::class : null,
        ]);

        if ($variant->generation !== null) {
            app(RecomputeImageGenerationStatusAction::class)($variant->generation);
        }
    }
}
