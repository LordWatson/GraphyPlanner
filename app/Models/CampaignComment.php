<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step C-5.3 — a message in the campaign's conversation. `internal_only` never reaches the portal.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int|null $user_id
 * @property string $body
 * @property bool $internal_only
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['campaign_id', 'user_id', 'body', 'internal_only'])]
class CampaignComment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'internal_only' => 'boolean',
        ];
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
