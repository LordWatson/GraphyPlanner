<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use Illuminate\Support\Facades\Log;

class DeleteAssetAction
{
    /**
     * Delete an asset. Step I-1.5: a soft delete — the row and its stored file are kept so the
     * asset can be restored and any post history that used it stays intact. Files are removed
     * from storage only when the client (or organization) is purged — see `PurgeClientAction`.
     */
    public function __invoke(Asset $asset): void
    {
        $asset->delete();

        Log::info('Asset deleted (soft)', [
            'org_id' => $asset->org_id,
            'client_id' => $asset->client_id,
            'asset_id' => $asset->id,
        ]);
    }
}
