<?php

namespace App\Models;

use Database\Factories\SuperAdminReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step 15.5 — a saved, named super admin report configuration. `filters` holds the same shape
 * `App\Support\SuperAdmin\AnalyticsFilters` reads from the analytics page's query string
 * (`range`, `from`, `to`, `org_ids`, `metrics`), so re-running a report never re-implements any
 * aggregation — it hands the filters straight to `GetPlatformAnalyticsSummaryAction`.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property array{range?: string, from?: string|null, to?: string|null, org_ids?: list<int>, metrics?: list<string>} $filters
 * @property int|null $created_by
 * @property Carbon|null $last_run_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description', 'filters', 'created_by', 'last_run_at'])]
class SuperAdminReport extends Model
{
    /** @use HasFactory<SuperAdminReportFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
