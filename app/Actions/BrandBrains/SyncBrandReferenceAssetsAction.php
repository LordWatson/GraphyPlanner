<?php

namespace App\Actions\BrandBrains;

use App\Contracts\AssetStorage;
use App\Enums\AssetType;
use App\Enums\ReferenceImageRole;
use App\Models\Asset;
use App\Models\BrandBrain;
use App\Models\BrandBrainReferenceAsset;
use App\Models\Client;
use App\Models\User;
use App\Services\AI\Images\ImageNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step 16.4: saves a client's Brand Brain visual references (ordered, each tagged Logo / Style /
 * Product). Only stored images of the same client are accepted, at most `ai.images.max_references`.
 * Each newly added reference gets a ≤1024px WebP copy made once here, so image generation never
 * re-processes originals. Removed references lose their normalised copy.
 */
class SyncBrandReferenceAssetsAction
{
    public function __construct(
        private readonly AssetStorage $storage,
        private readonly ImageNormalizer $normalizer,
    ) {}

    /**
     * @param  array<int, array{asset_id: int, role: string}>  $references  in display order
     */
    public function __invoke(Client $client, User $user, array $references): BrandBrain
    {
        $max = (int) config('ai.images.max_references', 8);
        $references = array_values($references);

        if (count($references) > $max) {
            throw ValidationException::withMessages(['references' => "Pick up to {$max} references."]);
        }

        $assetIds = array_map(fn (array $reference) => (int) $reference['asset_id'], $references);

        if (count($assetIds) !== count(array_unique($assetIds))) {
            throw ValidationException::withMessages(['references' => 'Each image can only be added once.']);
        }

        $assets = Asset::query()->whereKey($assetIds)->get()->keyBy('id');

        foreach ($assetIds as $assetId) {
            $asset = $assets->get($assetId);

            if ($asset === null || $asset->client_id !== $client->id) {
                throw ValidationException::withMessages(['references' => 'References must be images from this client\'s assets.']);
            }

            if (! $this->isStoredImage($asset)) {
                throw ValidationException::withMessages(['references' => "\"{$asset->original_filename}\" isn't an uploaded image. Upload the image file to use it as a reference."]);
            }
        }

        $removedFiles = [];

        $brandBrain = DB::transaction(function () use ($client, $references, $assets, $assetIds, &$removedFiles) {
            $brandBrain = $client->brandBrain ?? BrandBrain::create(['client_id' => $client->id]);

            $stale = $brandBrain->referenceAssets()->whereNotIn('asset_id', $assetIds)->get();

            foreach ($stale as $row) {
                if ($row->normalized_disk !== null && $row->normalized_path !== null) {
                    $removedFiles[] = [$row->normalized_disk, $row->normalized_path];
                }

                $row->delete();
            }

            foreach ($references as $position => $reference) {
                /** @var Asset $asset */
                $asset = $assets->get((int) $reference['asset_id']);

                $row = BrandBrainReferenceAsset::query()->firstOrNew([
                    'brand_brain_id' => $brandBrain->id,
                    'asset_id' => $asset->id,
                ]);

                $row->role = ReferenceImageRole::from($reference['role']);
                $row->position = $position;

                if ($row->normalized_path === null) {
                    $this->normalize($client, $asset, $row);
                }

                $row->save();
            }

            return $brandBrain;
        });

        foreach ($removedFiles as [$disk, $path]) {
            $this->storage->delete($disk, $path);
        }

        Log::info('Brand reference images synced', [
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'brand_brain_id' => $brandBrain->id,
            'user_id' => $user->id,
            'count' => count($references),
            'removed' => count($removedFiles),
        ]);

        return $brandBrain;
    }

    private function isStoredImage(Asset $asset): bool
    {
        $isImage = $asset->type === AssetType::Image || str_starts_with((string) $asset->mime_type, 'image/');

        return $isImage && $asset->disk !== null && $asset->path !== null;
    }

    private function normalize(Client $client, Asset $asset, BrandBrainReferenceAsset $row): void
    {
        $bytes = $this->storage->contents((string) $asset->disk, (string) $asset->path);

        if ($bytes === null) {
            throw ValidationException::withMessages(['references' => "We couldn't read \"{$asset->original_filename}\". Try uploading it again."]);
        }

        $normalized = $this->normalizer->reference($bytes);
        $stored = $this->storage->storeContents($normalized['bytes'], $normalized['mime_type'], "brand-references/{$client->org_id}/{$client->id}", 'webp');

        $row->normalized_disk = $stored['disk'];
        $row->normalized_path = $stored['path'];
        $row->normalized_mime_type = $normalized['mime_type'];
    }
}
