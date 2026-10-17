<?php

namespace App\Actions\AI\Images;

use App\Contracts\AssetStorage;
use App\Contracts\ImageProvider;
use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Enums\ImageErrorCode;
use App\Enums\ImageQualityTier;
use App\Enums\ImageVariantKind;
use App\Enums\ImageVariantStatus;
use App\Enums\ReferenceImageRole;
use App\Exceptions\AI\ImageProviderException;
use App\Models\AiImageGeneration;
use App\Models\AiImageVariant;
use App\Models\AiUsageLog;
use App\Models\Asset;
use App\Services\AI\Images\BrandReferenceLoader;
use App\Services\AI\Images\ImageNormalizer;
use App\Services\AI\Images\ImageProviderRouter;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\GeneratedImage;
use App\Support\AI\Images\ImageRenderOptions;
use App\Support\AI\Images\ReferenceImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Renders one AiImageVariant (Steps 16.7/16.8): the body of GenerateImageVariantJob.
 *
 * - Idempotent: a variant that already has an asset (or is no longer pending) is skipped, so job
 *   retries, double dispatches and page refreshes never trigger a second vendor call.
 * - Generate/regenerate render the set's brief with the variant's seed and the brand references;
 *   refine edits the parent image with the plain-words instruction; final re-renders the brief at
 *   the final tier with the chosen draft as the lead reference so the composition is kept.
 * - On success the original bytes are stored via AssetStorage::storeContents(), a WebP preview is
 *   made for the grid, and the Asset (`source = ai`, `is_ai_generated`, `variant_group_id` = set
 *   uuid) + variant + usage row are written in one transaction.
 * - A provider failure marks the variant failed with a typed error code and records a failed
 *   usage row; it is not rethrown (the adapter already retried), so the job doesn't pay again.
 */
class RenderImageVariantAction
{
    public function __construct(
        private readonly ImageProviderRouter $router,
        private readonly AssetStorage $storage,
        private readonly ImageNormalizer $normalizer,
        private readonly BrandReferenceLoader $references,
        private readonly RecomputeImageGenerationStatusAction $recompute,
    ) {}

    public function __invoke(AiImageVariant $variant): void
    {
        // Atomic claim: only a pending variant without an asset is ever rendered.
        $claimed = AiImageVariant::query()
            ->whereKey($variant->id)
            ->whereNull('asset_id')
            ->whereIn('status', [ImageVariantStatus::Queued->value, ImageVariantStatus::Generating->value])
            ->update(['status' => ImageVariantStatus::Generating->value, 'updated_at' => now()]);

        $generation = $variant->generation()->with(['client.brandBrain', 'client.organization'])->firstOrFail();

        if ($claimed === 0) {
            Log::info('AI image variant already rendered, skipped', $this->context($generation, $variant));

            return;
        }

        $variant->refresh();
        ($this->recompute)($generation);

        $brief = $generation->briefDto() ?? new AiImageBrief(subject: 'A clean, on-brand social media image');
        $provider = $this->router->for($brief, $this->context($generation, $variant));
        $context = [...$this->context($generation, $variant), 'provider' => $provider->name(), 'kind' => $variant->kind->value];
        $startedAt = microtime(true);

        try {
            $image = $this->render($provider, $generation, $variant, $brief, $context);
        } catch (ImageProviderException $e) {
            $this->fail($generation, $variant, $provider, $e->errorCode(), $startedAt, $context);

            return;
        }

        try {
            $this->store($generation, $variant, $provider, $image, $startedAt);
        } catch (Throwable $e) {
            Log::error('AI image variant could not be stored', [...$context, 'exception' => $e::class]);
            $this->fail($generation, $variant, $provider, ImageErrorCode::ProviderError, $startedAt, $context, billed: true);

            return;
        }

        Log::info('AI image variant ready', [...$context, 'duration_ms' => $this->elapsedMs($startedAt)]);

        ($this->recompute)($generation->refresh());
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function render(ImageProvider $provider, AiImageGeneration $generation, AiImageVariant $variant, AiImageBrief $brief, array $context): GeneratedImage
    {
        $parentImage = $variant->parent_variant_id !== null ? $this->parentImage($variant) : null;

        if ($variant->kind === ImageVariantKind::Refine) {
            if ($parentImage === null) {
                throw new ImageProviderException('Refine source image is missing');
            }

            return $provider->edit($parentImage, (string) $variant->instruction, new ImageRenderOptions(
                tier: $variant->quality_tier,
                aspectRatio: $generation->aspect_ratio,
                seed: $variant->seed,
                logContext: $context,
            ));
        }

        $references = [];

        if ($variant->kind === ImageVariantKind::Final && $parentImage !== null) {
            $references[] = $parentImage;
        }

        $references = [...$references, ...$this->references->forBrief($generation->client, $brief, count($references))];

        return $provider->generate($brief, new ImageRenderOptions(
            tier: $variant->quality_tier,
            aspectRatio: $generation->aspect_ratio,
            seed: $variant->seed,
            references: $references,
            logContext: $context,
        ));
    }

    private function parentImage(AiImageVariant $variant): ?ReferenceImage
    {
        $asset = $variant->parent?->asset;

        if ($asset === null || $asset->disk === null || $asset->path === null) {
            return null;
        }

        $bytes = $this->storage->contents($asset->disk, $asset->path);

        if ($bytes === null) {
            return null;
        }

        // Keep the payload small: the source travels as a ≤1024px WebP, never the 2K original.
        $normalized = $this->normalizer->reference($bytes);

        return new ReferenceImage($normalized['mime_type'], $normalized['bytes'], ReferenceImageRole::Draft);
    }

    private function store(AiImageGeneration $generation, AiImageVariant $variant, ImageProvider $provider, GeneratedImage $image, float $startedAt): void
    {
        $client = $generation->client;
        $directory = "ai-images/{$client->org_id}/{$client->id}";

        [$width, $height] = $image->width > 0 ? [$image->width, $image->height] : $this->normalizer->dimensions($image->bytes);

        $stored = $this->storage->storeContents($image->bytes, $image->mimeType, $directory, $image->extension());
        $preview = $this->normalizer->preview($image->bytes);
        $storedPreview = $this->storage->storeContents($preview['bytes'], $preview['mime_type'], "{$directory}/previews", 'webp');

        try {
            DB::transaction(function () use ($generation, $variant, $provider, $image, $stored, $storedPreview, $width, $height, $startedAt, $client) {
                $asset = Asset::create([
                    'org_id' => $client->org_id,
                    'client_id' => $client->id,
                    'campaign_id' => $generation->post?->campaign_id,
                    'uploaded_by' => $generation->requested_by,
                    'source' => AssetSource::Ai,
                    'type' => AssetType::Image,
                    'disk' => $stored['disk'],
                    'path' => $stored['path'],
                    'url' => $stored['url'],
                    'original_filename' => Str::slug($client->name).'-ai-'.$variant->id.'.'.$image->extension(),
                    'mime_type' => $image->mimeType,
                    'size' => $stored['size'],
                    'width' => $width,
                    'height' => $height,
                    'rights' => 'AI generated ('.$image->provider.' '.$image->model.')',
                    'variant_group_id' => $generation->uuid,
                    'ai_image_variant_id' => $variant->id,
                    'is_ai_generated' => true,
                ]);

                $variant->update([
                    'status' => ImageVariantStatus::Ready,
                    'asset_id' => $asset->id,
                    'preview_disk' => $storedPreview['disk'],
                    'preview_path' => $storedPreview['path'],
                    'provider_request_id' => $image->providerRequestId,
                    'duration_ms' => $this->elapsedMs($startedAt),
                    'cost_estimate' => $image->costEstimate,
                    'error_code' => null,
                ]);

                $this->recordUsage($generation, $variant, $provider, true, null, $image->costEstimate, $startedAt);

                $generation->increment('cost_estimate', $image->costEstimate);
            });
        } catch (Throwable $e) {
            $this->storage->delete($stored['disk'], $stored['path']);
            $this->storage->delete($storedPreview['disk'], $storedPreview['path']);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fail(AiImageGeneration $generation, AiImageVariant $variant, ImageProvider $provider, ImageErrorCode $code, float $startedAt, array $context, bool $billed = false): void
    {
        DB::transaction(function () use ($generation, $variant, $provider, $code, $startedAt, $billed) {
            $variant->update([
                'status' => ImageVariantStatus::Failed,
                'error_code' => $code,
                'duration_ms' => $this->elapsedMs($startedAt),
            ]);

            $this->recordUsage(
                $generation, $variant, $provider, false, $code,
                $billed ? $provider->costEstimate($variant->quality_tier) : 0.0,
                $startedAt,
            );
        });

        Log::warning('AI image variant failed', [...$context, 'error_code' => $code->value, 'duration_ms' => $this->elapsedMs($startedAt)]);

        ($this->recompute)($generation->refresh());
    }

    private function recordUsage(AiImageGeneration $generation, AiImageVariant $variant, ImageProvider $provider, bool $succeeded, ?ImageErrorCode $code, float $cost, float $startedAt): void
    {
        AiUsageLog::create([
            'org_id' => $generation->org_id,
            'client_id' => $generation->client_id,
            'user_id' => $generation->requested_by,
            'provider' => $provider->name(),
            'model' => $provider->model($variant->quality_tier),
            'method' => $variant->kind->usageMethod(),
            'succeeded' => $succeeded,
            'error_code' => $code?->value,
            'quality_tier' => $variant->quality_tier->value,
            'cost_estimate' => $cost,
            'duration_ms' => $this->elapsedMs($startedAt),
            'ai_image_variant_id' => $variant->id,
        ]);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function context(AiImageGeneration $generation, AiImageVariant $variant): array
    {
        return [
            'org_id' => $generation->org_id,
            'client_id' => $generation->client_id,
            'generation_id' => $generation->id,
            'variant_id' => $variant->id,
            'tier' => $variant->quality_tier === ImageQualityTier::Final ? 'final' : 'draft',
        ];
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
