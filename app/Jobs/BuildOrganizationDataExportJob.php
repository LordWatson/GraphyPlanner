<?php

namespace App\Jobs;

use App\Enums\DataExportStatus;
use App\Models\DataExport;
use App\Models\User;
use App\Notifications\DataExportReady;
use App\Services\Security\OrganizationDataExport;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Builds one organisation's data export and tells the person who asked for it.
 *
 * Unique per export row, so a double-click cannot run the same archive twice. On the `exports`
 * queue with a long timeout: this reads most of an organisation's tables, and the owner who asked
 * for it is waiting — finding 12 step 4 moved it off `reports`, where it had been queueing behind
 * the half-hourly engagement sweep.
 *
 * A failure is recorded on the row in words an owner can act on — an export that silently never
 * arrives is worse than one that says it failed.
 */
class BuildOrganizationDataExportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public readonly int $dataExportId)
    {
        $this->onQueue(Queues::EXPORTS);
    }

    public function uniqueId(): string
    {
        return (string) $this->dataExportId;
    }

    public function handle(OrganizationDataExport $builder): void
    {
        $export = DataExport::withoutGlobalScopes()->with('organization')->find($this->dataExportId);

        if (! $export || $export->status !== DataExportStatus::Pending) {
            Log::info('Data export missing or already finished, skipping', ['data_export_id' => $this->dataExportId]);

            return;
        }

        $organization = $export->organization;

        if ($organization === null) {
            $this->markFailed($export, 'The organisation was removed before the export finished.');

            return;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'alima-export-');

        if ($temporary === false) {
            $this->markFailed($export, 'The server could not create a temporary file.');

            return;
        }

        try {
            $summary = $builder->write($organization, $temporary);

            $disk = (string) config('assets.disk', 'local');
            $path = 'exports/'.$organization->id.'/'.Str::ulid().'.zip';
            $stream = fopen($temporary, 'r');

            if ($stream === false) {
                throw new RuntimeException('The archive could not be read back.');
            }

            // Streamed, not read into memory: the same code path has to work for an organisation
            // with a hundred thousand posts.
            Storage::disk($disk)->writeStream($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $export->forceFill([
                'status' => DataExportStatus::Ready,
                'disk' => $disk,
                'path' => $path,
                'size_bytes' => (int) filesize($temporary),
                'row_count' => $summary['rows'],
                'completed_at' => now(),
                'expires_at' => now()->addDays((int) config('exports.retention_days', 7)),
            ])->save();

            Log::info('Data export built', [
                'data_export_id' => $export->id,
                'org_id' => $organization->id,
                'sets' => $summary['sets'],
                'rows' => $summary['rows'],
                'size_bytes' => $export->size_bytes,
            ]);

            $this->notifyRequester($export);
        } catch (Throwable $e) {
            Log::error('Data export failed', [
                'data_export_id' => $export->id,
                'org_id' => $export->org_id,
                'error' => $e->getMessage(),
            ]);

            $this->markFailed($export, 'Something went wrong building the archive. Try again, and tell us if it keeps failing.');

            throw $e;
        } finally {
            @unlink($temporary);
        }
    }

    private function notifyRequester(DataExport $export): void
    {
        $requester = $export->requested_by === null
            ? null
            : User::query()->find($export->requested_by);

        $requester?->notify(new DataExportReady($export));
    }

    /**
     * Deliberately not named `fail()`: `InteractsWithQueue` already has one, and the queue
     * worker calls it.
     */
    private function markFailed(DataExport $export, string $reason): void
    {
        $export->forceFill([
            'status' => DataExportStatus::Failed,
            'failure_reason' => $reason,
            'completed_at' => now(),
        ])->save();
    }
}
