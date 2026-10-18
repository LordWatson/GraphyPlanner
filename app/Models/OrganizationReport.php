<?php

namespace App\Models;

use App\Enums\ReportSchedule;
use Database\Factories\OrganizationReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step 17.6 — a saved, named organization analytics view. `filters` holds the
 * `App\Support\Analytics\OrganizationAnalyticsFilters::toStoredArray()` shape (`range`, `from`,
 * `to`, `client_ids`, `campaign_ids`, `platforms`, `metrics`) — never an org id, which is this
 * row's own `org_id`. Running a report rebuilds those filters and hands them to
 * `GetOrganizationAnalyticsSummaryAction` with the *current* viewer, so permissions apply at run
 * time, not save time.
 *
 * Step 17.8: an optional email `schedule` sends it to `recipient_ids` (same-org staff), at most
 * once per period (`last_sent_at`).
 *
 * @property int $id
 * @property int $org_id
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed> $filters
 * @property int|null $created_by
 * @property Carbon|null $last_run_at
 * @property ReportSchedule|null $schedule
 * @property list<int>|null $recipient_ids
 * @property Carbon|null $last_sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'name', 'description', 'filters', 'created_by', 'last_run_at', 'schedule', 'recipient_ids', 'last_sent_at'])]
class OrganizationReport extends Model
{
    /** @use HasFactory<OrganizationReportFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'recipient_ids' => 'array',
            'schedule' => ReportSchedule::class,
            'last_run_at' => 'datetime',
            'last_sent_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return list<int>
     */
    public function recipientIds(): array
    {
        return array_map('intval', $this->recipient_ids ?? []);
    }
}
