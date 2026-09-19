<?php

namespace App\Actions\Assets;

use App\Contracts\AssetStorage;
use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateAssetAction
{
    public function __construct(private AssetStorage $storage) {}

    /**
     * Upload or link a new asset for the given client (optionally scoped to a campaign).
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Client $client, User $uploader, array $data): Asset
    {
        return DB::transaction(function () use ($client, $uploader, $data) {
            $source = $data['source'] instanceof AssetSource ? $data['source'] : AssetSource::from($data['source']);

            $attributes = [
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'campaign_id' => $data['campaign_id'] ?? null,
                'uploaded_by' => $uploader->id,
                'source' => $source,
                'type' => $data['type'] ?? null,
                'rights' => $data['rights'] ?? null,
                'variant_group_id' => $data['variant_group_id'] ?? null,
            ];

            if ($source === AssetSource::Upload) {
                $file = $data['file'];
                $directory = "assets/{$client->org_id}/{$client->id}";
                $stored = $this->storage->store($file, $directory);

                $attributes = [
                    ...$attributes,
                    'type' => $attributes['type'] ?? $this->detectType($stored['mime_type']),
                    'disk' => $stored['disk'],
                    'path' => $stored['path'],
                    'url' => $stored['url'],
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $stored['mime_type'],
                    'size' => $stored['size'],
                ];
            } else {
                $attributes['url'] = $data['url'];
            }

            $asset = Asset::create($attributes);

            Log::info('Asset created', [
                'client_id' => $client->id,
                'asset_id' => $asset->id,
                'source' => $source->value,
            ]);

            return $asset;
        });
    }

    /**
     * Infer the `AssetType` from a stored upload's mime type when the caller didn't explicitly
     * pick one, so downstream consumers (e.g. `UploadPostAdapter`'s Instagram REELS/IMAGE
     * `media_type` inference) work for video uploads without requiring manual tagging.
     */
    private function detectType(?string $mimeType): ?AssetType
    {
        if ($mimeType === null) {
            return null;
        }

        return match (true) {
            str_starts_with($mimeType, 'image/') => AssetType::Image,
            str_starts_with($mimeType, 'video/') => AssetType::Video,
            $mimeType === 'application/pdf' => AssetType::Document,
            default => AssetType::Other,
        };
    }
}
