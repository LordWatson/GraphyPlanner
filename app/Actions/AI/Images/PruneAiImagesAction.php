<?php

namespace App\Actions\AI\Images;

use App\Contracts\AssetStorage;
use App\Models\AiImageVariant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step 16.13 housekeeping: deletes the files (original + preview) and Asset row of AI variants
 * that were discarded, or never selected, more than `ai.images.prune_after_days` ago. Any asset
 * attached to a post is always kept. The variant row stays (lineage, usage), just without files.
 */
class PruneAiImagesAction
{
    public function __construct(private readonly AssetStorage $storage) {}

    /**
     * @return array{variants: int, files: int}
     */
    public function __invoke(?int $days = null): array
    {
        $cutoff = CarbonImmutable::now()->subDays($days ?? (int) config('ai.images.prune_after_days', 30));
        $variants = 0;
        $files = 0;

        AiImageVariant::query()
            ->with('asset')
            ->where(fn (Builder $query) => $query->whereNotNull('asset_id')->orWhereNotNull('preview_path'))
            ->where(fn (Builder $query) => $query
                ->where('discarded_at', '<=', $cutoff)
                ->orWhere(fn (Builder $query) => $query->whereNull('selected_at')->where('created_at', '<=', $cutoff)))
            ->where(fn (Builder $query) => $query
                ->whereNull('asset_id')
                ->orWhereDoesntHave('asset.posts'))
            ->chunkById(200, function ($chunk) use (&$variants, &$files) {
                foreach ($chunk as $variant) {
                    $paths = [];

                    if ($variant->asset?->disk !== null && $variant->asset->path !== null) {
                        $paths[] = [$variant->asset->disk, $variant->asset->path];
                    }

                    if ($variant->preview_disk !== null && $variant->preview_path !== null) {
                        $paths[] = [$variant->preview_disk, $variant->preview_path];
                    }

                    DB::transaction(function () use ($variant) {
                        $asset = $variant->asset;
                        $variant->update(['asset_id' => null, 'preview_disk' => null, 'preview_path' => null]);
                        $asset?->delete();
                    });

                    foreach ($paths as [$disk, $path]) {
                        $this->storage->delete($disk, $path);
                        $files++;
                    }

                    $variants++;
                }
            });

        Log::info('AI images pruned', ['variants' => $variants, 'files' => $files, 'cutoff' => $cutoff->toDateString()]);

        return ['variants' => $variants, 'files' => $files];
    }
}
