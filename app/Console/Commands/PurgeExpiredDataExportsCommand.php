<?php

namespace App\Console\Commands;

use App\Contracts\AssetStorage;
use App\Enums\DataExportStatus;
use App\Models\DataExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enforces the data-export retention policy: once an archive is past
 * `config('exports.retention_days')` the file is deleted and the row is marked expired.
 *
 * This command *is* the policy. A copy of an organisation's whole record should not sit on a disk
 * indefinitely because somebody pressed a button once, and the row remaining as a receipt is what
 * lets an owner see that it was taken and that it is gone.
 */
class PurgeExpiredDataExportsCommand extends Command
{
    protected $signature = 'exports:purge-expired';

    protected $description = 'Delete organisation data exports past their retention window';

    public function handle(AssetStorage $storage): int
    {
        $deleted = 0;
        $failed = 0;

        DataExport::query()
            ->withoutGlobalScopes()
            ->where('status', DataExportStatus::Ready)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->each(function (DataExport $export) use ($storage, &$deleted, &$failed) {
                if ($export->path !== null) {
                    try {
                        $storage->delete((string) $export->disk, $export->path);
                    } catch (Throwable $e) {
                        $failed++;
                        Log::warning('Could not delete an expired data export file', [
                            'data_export_id' => $export->id,
                            'error' => $e->getMessage(),
                        ]);

                        // The row is still marked expired: the link must stop working even if the
                        // file is stuck, and a file nothing points at is the lesser problem.
                    }
                }

                // `disk`/`path` are cleared so nothing can try to serve bytes that are gone.
                $export->forceFill([
                    'status' => DataExportStatus::Expired,
                    'disk' => null,
                    'path' => null,
                ])->save();

                $deleted++;
            });

        Log::info('Expired data exports purged', ['purged' => $deleted, 'files_failed' => $failed]);
        $this->info("Purged {$deleted} expired export(s).");

        return self::SUCCESS;
    }
}
