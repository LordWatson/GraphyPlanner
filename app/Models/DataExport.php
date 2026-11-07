<?php

namespace App\Models;

use App\Enums\DataExportStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One request for "everything you hold about this organisation".
 *
 * The row outlives the file on purpose: once the retention window passes the archive is deleted
 * and this becomes a receipt — the export happened, and it is gone. An owner asking "did my data
 * leave in a file somewhere?" can read the answer off this table.
 *
 * @property int $id
 * @property int $org_id
 * @property int|null $requested_by
 * @property DataExportStatus $status
 * @property string|null $disk
 * @property string|null $path
 * @property int|null $size_bytes
 * @property int|null $row_count
 * @property string|null $failure_reason
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'requested_by', 'status', 'disk', 'path', 'size_bytes', 'row_count', 'failure_reason', 'completed_at', 'expires_at'])]
class DataExport extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DataExportStatus::class,
            'size_bytes' => 'integer',
            'row_count' => 'integer',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Ready, still inside its retention window, and the file was actually written. Checked on every
     * download rather than trusted from the status alone: the purge sweep and this check must agree
     * even if the sweep has not run yet.
     */
    public function isDownloadable(): bool
    {
        return $this->status === DataExportStatus::Ready
            && $this->path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * An export that is still being built. Used to refuse a second request while one is running —
     * the archive is the whole organisation, and two at once is only ever a doubled bill.
     *
     * @param  Builder<DataExport>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', DataExportStatus::Pending);
    }
}
