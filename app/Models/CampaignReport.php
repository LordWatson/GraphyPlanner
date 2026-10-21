<?php

namespace App\Models;

use App\Enums\CampaignReportStatus;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step C-7.3 — the campaign's wrap report. `kpi_snapshot` (and, from M-3.1, `engagement_snapshot`)
 * are frozen when the report is shared so the numbers the client saw never change; `version`
 * counts shares.
 *
 * @property int $id
 * @property int $campaign_id
 * @property string|null $summary
 * @property list<string>|null $highlights
 * @property string|null $learnings
 * @property string|null $next_steps
 * @property list<array<string, mixed>>|null $kpi_snapshot
 * @property array<string, mixed>|null $engagement_snapshot
 * @property CampaignReportStatus $status
 * @property int $version
 * @property Carbon|null $shared_at
 * @property int|null $shared_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'campaign_id', 'summary', 'highlights', 'learnings', 'next_steps', 'kpi_snapshot', 'engagement_snapshot', 'status',
    'version', 'shared_at', 'shared_by',
])]
class CampaignReport extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'version' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'highlights' => 'array',
            'kpi_snapshot' => 'array',
            'engagement_snapshot' => 'array',
            'status' => CampaignReportStatus::class,
            'version' => 'integer',
            'shared_at' => 'datetime',
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
    public function sharer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }
}
