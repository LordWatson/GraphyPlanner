<?php

namespace App\Models;

use App\Enums\AssetSource;
use App\Enums\AssetType;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
 * @property string|null $rights
 * @property string|null $variant_group_id
 * @property int|null $ai_image_variant_id
 * @property bool $is_ai_generated
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'org_id', 'client_id', 'campaign_id', 'uploaded_by', 'source', 'type', 'disk', 'path', 'url',
    'original_filename', 'mime_type', 'size', 'width', 'height', 'rights', 'variant_group_id',
    'ai_image_variant_id', 'is_ai_generated',
])]
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => AssetSource::class,
            'type' => AssetType::class,
            'is_ai_generated' => 'boolean',
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
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
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
        return $this->belongsTo(AiImageVariant::class);
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
