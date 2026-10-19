<?php

namespace App\Jobs;

use App\Contracts\AssetStorage;
use App\Contracts\MediaProber;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Scopes\OrganizationScope;
use App\Support\Media\VideoProbe;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Step I-3.5 / M-2.4 — runs after an upload: fills the asset's real size (images: as displayed,
 * EXIF rotation applied; videos: via `MediaProber`), a video's length and codec, and a small WebP
 * thumbnail that lists and grids show instead of the full-resolution original.
 *
 * The publish checklist reads these to block media a platform would reject (an over-length
 * TikTok, a landscape Story). Never fails loudly: a file it can't read is marked probed with
 * whatever it learned, so the checklist skips the checks it can't make.
 */
class ProbeAssetJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const THUMBNAIL_LONG_EDGE = 480;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public readonly int $assetId)
    {
        $this->onQueue(Queues::DEFAULT);
    }

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    public function handle(AssetStorage $storage, MediaProber $prober): void
    {
        /** @var Asset|null $asset */
        $asset = Asset::query()->withoutGlobalScope(OrganizationScope::class)->find($this->assetId);

        if ($asset === null || blank($asset->disk) || blank($asset->path)) {
            return;
        }

        $bytes = $storage->contents((string) $asset->disk, (string) $asset->path);

        if ($bytes === null) {
            Log::warning('ProbeAssetJob: file missing', ['asset_id' => $asset->id]);

            return;
        }

        $updates = match ($asset->type) {
            AssetType::Image => $this->probeImage($asset, $bytes, $storage),
            AssetType::Video => $this->probeVideo($asset, $bytes, $storage, $prober),
            default => [],
        };

        $asset->forceFill([...$updates, 'probed_at' => now()])->save();

        Log::info('Asset probed', [
            'asset_id' => $asset->id,
            'org_id' => $asset->org_id,
            'type' => $asset->type?->value,
            'width' => $asset->width,
            'height' => $asset->height,
            'duration_ms' => $asset->duration_ms,
            'has_thumbnail' => filled($asset->thumbnail_path),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function probeImage(Asset $asset, string $bytes, AssetStorage $storage): array
    {
        try {
            $image = ImageManager::gd()->read($bytes);
            $updates = ['width' => $image->width(), 'height' => $image->height()];

            // Animated GIFs keep their original as the "thumbnail" so they still move in lists.
            if ($asset->mime_type !== 'image/gif') {
                $updates['thumbnail_path'] = $this->storeThumbnail($asset, $bytes, $storage);
            }

            return $updates;
        } catch (Throwable $e) {
            Log::warning('ProbeAssetJob: image could not be read', ['asset_id' => $asset->id, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function probeVideo(Asset $asset, string $bytes, AssetStorage $storage, MediaProber $prober): array
    {
        $local = tempnam(sys_get_temp_dir(), 'probe');

        if ($local === false) {
            return [];
        }

        try {
            file_put_contents($local, $bytes);

            $probe = $prober->probe($local);
            $updates = $probe === null ? [] : array_filter([
                'width' => $probe->width,
                'height' => $probe->height,
                'duration_ms' => $probe->durationMs,
                'video_codec' => $probe->codec,
            ], fn ($value) => $value !== null);

            $durationMs = $probe instanceof VideoProbe ? $probe->durationMs : null;
            $frame = $prober->frame($local, min(1000, (int) (($durationMs ?? 2000) / 2)));

            if ($frame !== null) {
                $updates['thumbnail_path'] = $this->storeThumbnail($asset, $frame, $storage);
            }

            return $updates;
        } finally {
            @unlink($local);
        }
    }

    private function storeThumbnail(Asset $asset, string $bytes, AssetStorage $storage): ?string
    {
        try {
            $image = ImageManager::gd()->read($bytes);
            $image->scaleDown(self::THUMBNAIL_LONG_EDGE, self::THUMBNAIL_LONG_EDGE);

            $stored = $storage->storeContents(
                (string) $image->toWebp(78),
                'image/webp',
                "thumbnails/{$asset->org_id}/{$asset->client_id}",
                'webp',
            );

            if (filled($asset->thumbnail_path) && $asset->thumbnail_path !== $stored['path']) {
                $storage->delete((string) $asset->disk, (string) $asset->thumbnail_path);
            }

            return $stored['path'];
        } catch (Throwable $e) {
            Log::warning('ProbeAssetJob: thumbnail failed', ['asset_id' => $asset->id, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
