<?php

namespace App\Actions\Assets;

use App\Contracts\AssetStorage;
use App\Enums\AssetSource;
use App\Models\Asset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteAssetAction
{
    public function __construct(private AssetStorage $storage) {}

    /**
     * Delete an asset, removing its stored file (if any) first.
     */
    public function __invoke(Asset $asset): void
    {
        DB::transaction(function () use ($asset) {
            if ($asset->source === AssetSource::Upload && $asset->disk && $asset->path) {
                $this->storage->delete($asset->disk, $asset->path);
            }

            $asset->delete();

            Log::info('Asset deleted', [
                'client_id' => $asset->client_id,
                'asset_id' => $asset->id,
            ]);
        });
    }
}
