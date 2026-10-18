<?php

namespace App\Models;

use App\Enums\CampaignMetric;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step C-7.1 — one KPI target on a campaign (one per metric).
 *
 * @property int $id
 * @property int $campaign_id
 * @property CampaignMetric $metric
 * @property string $target
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['campaign_id', 'metric', 'target', 'sort'])]
class CampaignKpi extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric' => CampaignMetric::class,
            'target' => 'decimal:2',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class)->withoutGlobalScope(OrganizationScope::class);
    }
}
