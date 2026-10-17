<?php

namespace App\Models;

use App\Enums\ImageErrorCode;
use App\Enums\ImageQualityTier;
use App\Enums\ImageVariantKind;
use App\Enums\ImageVariantStatus;
use Database\Factories\AiImageVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One tile in an AiImageGeneration set (Step 16.3), including refine/regenerate/final children
 * (`parent_variant_id` lineage). `idempotency_key` is unique, so a variant is rendered (and paid
 * for) at most once however many times its job runs.
 *
 * @property int $id
 * @property int $generation_id
 * @property int $org_id
 * @property int $position
 * @property ImageVariantKind $kind
 * @property ImageVariantStatus $status
 * @property int|null $asset_id
 * @property int|null $parent_variant_id
 * @property ImageQualityTier $quality_tier
 * @property string $idempotency_key
 * @property int|null $seed
 * @property string|null $instruction
 * @property string|null $preview_disk
 * @property string|null $preview_path
 * @property string|null $provider_request_id
 * @property int|null $duration_ms
 * @property ImageErrorCode|null $error_code
 * @property string $cost_estimate
 * @property Carbon|null $selected_at
 * @property Carbon|null $discarded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'generation_id', 'org_id', 'position', 'kind', 'status', 'asset_id', 'parent_variant_id', 'quality_tier',
    'idempotency_key', 'seed', 'instruction', 'preview_disk', 'preview_path', 'provider_request_id',
    'duration_ms', 'error_code', 'cost_estimate', 'selected_at', 'discarded_at',
])]
class AiImageVariant extends Model
{
    /** @use HasFactory<AiImageVariantFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ImageVariantKind::class,
            'status' => ImageVariantStatus::class,
            'quality_tier' => ImageQualityTier::class,
            'error_code' => ImageErrorCode::class,
            'seed' => 'integer',
            'duration_ms' => 'integer',
            'cost_estimate' => 'decimal:4',
            'selected_at' => 'datetime',
            'discarded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiImageGeneration, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiImageGeneration::class, 'generation_id');
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<AiImageVariant, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_variant_id');
    }

    /**
     * @return HasMany<AiImageVariant, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_variant_id');
    }
}
