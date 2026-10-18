<?php

namespace App\Models;

use App\Enums\CampaignColour;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\CampaignPhaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Step C-3.1 — a stretch of a campaign (Teaser, Launch, Sustain, Wrap…). Must sit within the
 * campaign's dates; may be undated while the campaign is.
 *
 * @property int $id
 * @property int $campaign_id
 * @property string $name
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property int $sort
 * @property CampaignColour|null $colour
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['campaign_id', 'name', 'starts_on', 'ends_on', 'sort', 'colour'])]
class CampaignPhase extends Model
{
    /** @use HasFactory<CampaignPhaseFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'sort' => 'integer',
            'colour' => CampaignColour::class,
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
     * @return HasMany<CampaignDeliverable, $this>
     */
    public function deliverables(): HasMany
    {
        return $this->hasMany(CampaignDeliverable::class);
    }
}
