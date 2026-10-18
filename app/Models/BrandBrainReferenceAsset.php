<?php

namespace App\Models;

use App\Enums\ReferenceImageRole;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Brand Brain visual reference (Step 16.4): a client image tagged Logo/Style/Product, with a
 * normalised ≤1024px copy made once on save so image generation never re-processes originals.
 *
 * @property int $id
 * @property int $brand_brain_id
 * @property int $asset_id
 * @property ReferenceImageRole $role
 * @property int $position
 * @property string|null $normalized_disk
 * @property string|null $normalized_path
 * @property string|null $normalized_mime_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['brand_brain_id', 'asset_id', 'role', 'position', 'normalized_disk', 'normalized_path', 'normalized_mime_type'])]
class BrandBrainReferenceAsset extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ReferenceImageRole::class,
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BrandBrain, $this>
     */
    public function brandBrain(): BelongsTo
    {
        return $this->belongsTo(BrandBrain::class);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withoutGlobalScope(OrganizationScope::class);
    }
}
