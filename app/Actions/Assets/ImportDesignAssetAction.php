<?php

namespace App\Actions\Assets;

use App\Contracts\AssetStorage;
use App\Enums\AssetType;
use App\Jobs\ProbeAssetJob;
use App\Models\Asset;
use App\Models\Client;
use App\Models\DesignImportConnection;
use App\Models\User;
use App\Services\DesignImports\DesignImportManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step M-5.9 — import one file from a design/cloud source into a client's asset library. The bytes
 * go through the normal **private** asset pipeline: stored via `AssetStorage` (signed URLs only,
 * like any upload), tagged with their source, then probed (M-2.4) for size/dimensions/thumbnail.
 * Optionally attaches to a campaign's asset kit (C-4.1) via `campaign_id`.
 *
 * A dead connection surfaces as `DesignImportReconnectRequired` (thrown by the source) so the
 * caller can prompt a reconnect. Tokens are never logged.
 */
class ImportDesignAssetAction
{
    public function __construct(
        private AssetStorage $storage,
        private DesignImportManager $manager,
    ) {}

    public function __invoke(
        DesignImportConnection $connection,
        string $fileId,
        Client $client,
        ?User $user = null,
        ?int $campaignId = null,
    ): Asset {
        // The external download happens before the transaction (it can throw ReconnectRequired).
        $file = $this->manager->for($connection->provider)->download($connection, $fileId);

        $asset = DB::transaction(function () use ($connection, $file, $client, $user, $campaignId) {
            $stored = $this->storage->storeContents(
                $file->contents,
                $file->mimeType,
                "assets/{$client->org_id}/{$client->id}",
                $file->extension,
            );

            $asset = Asset::create([
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'campaign_id' => $campaignId,
                'uploaded_by' => $user?->id,
                'source' => $connection->provider->assetSource(),
                'type' => $this->detectType($stored['mime_type']),
                'disk' => $stored['disk'],
                'path' => $stored['path'],
                'url' => $stored['url'],
                'original_filename' => $file->filename,
                'mime_type' => $stored['mime_type'],
                'size' => $stored['size'],
            ]);

            ProbeAssetJob::dispatch($asset->id)->afterCommit();

            return $asset;
        });

        Log::info('Asset imported from design source', [
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'asset_id' => $asset->id,
            'provider' => $connection->provider->value,
            'connection_id' => $connection->id,
            'user_id' => $user?->id,
        ]);

        return $asset;
    }

    private function detectType(?string $mimeType): AssetType
    {
        return match (true) {
            $mimeType !== null && str_starts_with($mimeType, 'image/') => AssetType::Image,
            $mimeType !== null && str_starts_with($mimeType, 'video/') => AssetType::Video,
            $mimeType === 'application/pdf' => AssetType::Document,
            default => AssetType::Other,
        };
    }
}
