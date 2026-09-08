<?php

namespace App\Actions\Assets;

use App\Contracts\AssetStorage;
use App\Enums\AssetSource;
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
}
