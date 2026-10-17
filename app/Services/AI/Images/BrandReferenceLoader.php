<?php

namespace App\Services\AI\Images;

use App\Contracts\AssetStorage;
use App\Enums\ReferenceImageRole;
use App\Models\BrandBrainReferenceAsset;
use App\Models\Client;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\ReferenceImage;
use Illuminate\Support\Facades\Log;

/**
 * Loads a brief's Brand Brain reference images (Step 16.4) as ReferenceImage DTOs, reading only
 * the ≤1024px normalised copies made on save, never the originals. Logos first, capped at
 * `ai.images.max_references`. A missing file is skipped (and logged), never fatal.
 */
class BrandReferenceLoader
{
    public function __construct(private readonly AssetStorage $storage) {}

    /**
     * @return array<int, ReferenceImage>
     */
    public function forBrief(Client $client, AiImageBrief $brief, int $reserve = 0): array
    {
        $brandBrain = $client->brandBrain;

        if ($brandBrain === null || $brief->referenceAssetIds === []) {
            return [];
        }

        $limit = max(0, (int) config('ai.images.max_references', 8) - $reserve);

        $rows = $brandBrain->referenceAssets()
            ->whereIn('asset_id', $brief->referenceAssetIds)
            ->whereNotNull('normalized_path')
            ->get()
            ->sortBy(fn (BrandBrainReferenceAsset $row) => [$row->role === ReferenceImageRole::Logo ? 0 : 1, $row->position])
            ->take($limit);

        $references = [];

        foreach ($rows as $row) {
            $bytes = $this->storage->contents((string) $row->normalized_disk, (string) $row->normalized_path);

            if ($bytes === null) {
                Log::warning('Brand reference image file missing, skipped', [
                    'org_id' => $client->org_id,
                    'client_id' => $client->id,
                    'asset_id' => $row->asset_id,
                ]);

                continue;
            }

            $references[] = new ReferenceImage((string) ($row->normalized_mime_type ?? 'image/webp'), $bytes, $row->role);
        }

        return $references;
    }
}
