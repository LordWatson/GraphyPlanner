<?php

namespace App\Models;

use App\Enums\ImageAspectRatio;
use App\Enums\ImageGenerationStatus;
use App\Enums\ImageQualityTier;
use App\Support\AI\Images\AiImageBrief;
use Database\Factories\AiImageGenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One "Generate" click in the AI Image Studio (Step 16.3): the art-direction brief every variant
 * shares, the format, and the derived status of its variants. Internal-only: never exposed to a
 * ClientReviewer, who only ever sees an attached Asset.
 *
 * @property int $id
 * @property string $uuid
 * @property int $org_id
 * @property int $client_id
 * @property int|null $ai_post_idea_id
 * @property int|null $post_id
 * @property int|null $requested_by
 * @property string $request_id
 * @property array<string, mixed>|null $brief
 * @property array<string, mixed>|null $art_direction
 * @property string|null $brief_hash
 * @property string $provider
 * @property string $model
 * @property ImageQualityTier $quality_tier
 * @property ImageAspectRatio $aspect_ratio
 * @property int $variant_count
 * @property ImageGenerationStatus $status
 * @property string $cost_estimate
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'uuid', 'org_id', 'client_id', 'ai_post_idea_id', 'post_id', 'requested_by', 'request_id', 'brief',
    'art_direction', 'brief_hash', 'provider', 'model', 'quality_tier', 'aspect_ratio', 'variant_count',
    'status', 'cost_estimate',
])]
class AiImageGeneration extends Model
{
    /** @use HasFactory<AiImageGenerationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'brief' => 'array',
            'art_direction' => 'array',
            'quality_tier' => ImageQualityTier::class,
            'aspect_ratio' => ImageAspectRatio::class,
            'status' => ImageGenerationStatus::class,
            'variant_count' => 'integer',
            'cost_estimate' => 'decimal:4',
        ];
    }

    public function briefDto(): ?AiImageBrief
    {
        return $this->brief === null ? null : AiImageBrief::fromArray($this->brief);
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
     * @return BelongsTo<AiPostIdea, $this>
     */
    public function idea(): BelongsTo
    {
        return $this->belongsTo(AiPostIdea::class, 'ai_post_idea_id');
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return HasMany<AiImageVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(AiImageVariant::class, 'generation_id')->orderBy('position');
    }
}
