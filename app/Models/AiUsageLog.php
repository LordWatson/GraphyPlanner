<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One AI vendor call, success or failure (Step 16.11; Step 2.8 should reuse this table for text).
 *
 * @property int $id
 * @property int $org_id
 * @property int|null $client_id
 * @property int|null $campaign_id
 * @property int|null $user_id
 * @property string $provider
 * @property string $model
 * @property string $method
 * @property bool $succeeded
 * @property string|null $error_code
 * @property string|null $quality_tier
 * @property string $cost_estimate
 * @property int|null $duration_ms
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property int|null $ai_image_variant_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'org_id', 'client_id', 'campaign_id', 'user_id', 'provider', 'model', 'method', 'succeeded', 'error_code', 'quality_tier',
    'cost_estimate', 'duration_ms', 'prompt_tokens', 'completion_tokens', 'ai_image_variant_id',
])]
class AiUsageLog extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'cost_estimate' => 'decimal:4',
            'duration_ms' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
