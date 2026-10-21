<?php

namespace App\Models;

use App\Enums\ClientReportStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step M-3.3 / I-10.3 (Phase 8) — one client's monthly report. `snapshot` is the client-safe
 * figure set built by `BuildClientReportSnapshotAction` (never billing data); it's refreshed while
 * a draft and frozen once published. `commentary` is the agency's note to the client.
 *
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property ClientReportStatus $status
 * @property string|null $commentary
 * @property array<string, mixed>|null $snapshot
 * @property Carbon|null $snapshot_at
 * @property Carbon|null $published_at
 * @property int|null $published_by
 * @property Carbon|null $sent_at
 * @property int $recipients_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'org_id', 'client_id', 'period_start', 'period_end', 'status', 'commentary', 'snapshot',
    'snapshot_at', 'published_at', 'published_by', 'sent_at', 'recipients_count',
])]
class ClientReport extends Model
{
    use BelongsToOrganization;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'recipients_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => ClientReportStatus::class,
            'snapshot' => 'array',
            'snapshot_at' => 'datetime',
            'published_at' => 'datetime',
            'sent_at' => 'datetime',
            'recipients_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed()->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isPublished(): bool
    {
        return $this->status === ClientReportStatus::Published;
    }

    /**
     * "October 2026".
     */
    public function periodLabel(): string
    {
        return CarbonImmutable::parse($this->period_start->toDateString())->format('F Y');
    }
}
