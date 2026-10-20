<?php

namespace App\Models;

use App\Contracts\AssetStorage;
use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use App\Support\StoredFileUrl;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property int|null $campaign_id
 * @property int|null $uploaded_by
 * @property AssetSource $source
 * @property AssetType|null $type
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $url
 * @property string|null $original_filename
 * @property string|null $mime_type
 * @property int|null $size
 * @property int|null $width
 * @property int|null $height
 * @property int|null $duration_ms
 * @property string|null $video_codec
 * @property string|null $thumbnail_path
 * @property Carbon|null $probed_at
 * @property string|null $rights
 * @property string|null $variant_group_id
 * @property int|null $source_asset_id
 * @property int|null $ai_image_variant_id
 * @property bool $is_ai_generated
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'org_id', 'client_id', 'campaign_id', 'uploaded_by', 'source', 'type', 'disk', 'path', 'url',
    'original_filename', 'mime_type', 'size', 'width', 'height', 'rights', 'variant_group_id', 'source_asset_id',
    'ai_image_variant_id', 'is_ai_generated', 'duration_ms', 'video_codec', 'thumbnail_path', 'probed_at',
])]
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /**
     * Step I-1.6: a private file's URL is signed and short-lived, so it's minted on every read
     * instead of trusting the URL stored at upload time.
     *
     * @return Attribute<string|null, never>
     */
    protected function url(): Attribute
    {
        return Attribute::make(get: fn (?string $value): ?string => StoredFileUrl::resolve($this->disk, $this->path, $value));
    }

    /**
     * Step I-3.5 / M-2.4: a small preview (made by `ProbeAssetJob`) for lists and grids, so they
     * never load the full-resolution original. Falls back to the original's URL for images that
     * haven't been probed yet; null for a video without one.
     */
    public function thumbnailUrl(): ?string
    {
        if (filled($this->thumbnail_path) && filled($this->disk)) {
            return app(AssetStorage::class)->url((string) $this->disk, (string) $this->thumbnail_path);
        }

        return $this->type === AssetType::Video ? null : $this->url;
    }

    /**
     * Width ÷ height, when known.
     */
    public function aspectRatio(): ?float
    {
        return $this->width && $this->height ? $this->width / $this->height : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => AssetSource::class,
            'type' => AssetType::class,
            'is_ai_generated' => 'boolean',
            'probed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        // Step I-1.5: an archived (soft-deleted) client still owns its records.
        return $this->belongsTo(Client::class)->withTrashed()->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The Image Studio variant this asset was rendered for (Step 16.2), when AI generated.
     *
     * @return BelongsTo<AiImageVariant, $this>
     */
    public function aiImageVariant(): BelongsTo
    {
        return $this->belongsTo(AiImageVariant::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * Step 18.4: the image this one was sliced from by a grid split (the source is never changed).
     *
     * @return BelongsTo<Asset, $this>
     */
    public function sourceAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'source_asset_id')->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * Posts (Step 0.10 editor UI) this asset is attached to as media.
     *
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_assets');
    }
}
