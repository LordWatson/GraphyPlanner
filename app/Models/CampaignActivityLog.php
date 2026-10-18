<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step C-0.3 — the campaign's audit trail. `event` is a dotted name (`status.changed`,
 * `brief.sent`, `campaign.paused`…), `from`/`to` carry the before/after value where one applies,
 * and `meta` holds ids (affected posts, revision ids) — never secrets or free text from the AI.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int|null $user_id
 * @property string $event
 * @property string|null $from
 * @property string|null $to
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 */
#[Fillable(['campaign_id', 'user_id', 'event', 'from', 'to', 'meta', 'created_at'])]
class CampaignActivityLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
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
