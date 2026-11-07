<?php

namespace App\Console\Commands;

use App\Services\LocalAssetStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Moves every stored file from one filesystem disk to another and repoints the rows at it — the
 * one-off that has to run when `ASSETS_DISK` changes from the private `local` disk to `s3`.
 *
 * Without it, flipping the config orphans everything already uploaded: each row remembers the disk
 * it was written to, so old files keep being read from a local disk that a second web node (or the
 * next container) does not have.
 *
 * Three deliberate choices:
 *
 *  - **Nothing is deleted.** The source file stays where it is, so the command is safe to re-run,
 *    safe to interrupt, and a rollback is just flipping the config back. Removing the originals is
 *    a separate decision for once the move has been verified.
 *  - **Files are streamed, never read whole.** A 500MB video (`assets.max_video_upload_size_kb`)
 *    must not have to fit in memory.
 *  - **One row's failure does not stop the run.** It is counted and logged with its table and id,
 *    because the alternative is a half-moved library and no list of what is left.
 *
 * `Storage` is used directly rather than the `AssetStorage` contract on purpose: the contract
 * exists so the rest of the app never has to know which disk a file is on, and this command's
 * whole job is to know about two of them at once.
 */
class MigrateStoredFilesDiskCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'storage:migrate-disk
        {--from=local : The disk files are currently on}
        {--to= : The disk to move them to (defaults to config(assets.disk))}
        {--chunk=200 : Rows per batch}
        {--dry-run : Report what would move and change nothing}';

    /**
     * @var string
     */
    protected $description = 'Copy every stored file to another disk and repoint the rows at it (e.g. local → s3)';

    /**
     * Every table that stores a file, as `[table, disk column, path columns, path column => url
     * column]`.
     *
     * `assets` has two paths behind one disk column (the original and the probe thumbnail), so
     * both must copy before the disk is flipped — hence paths being a list.
     *
     * A url column only matters for a public target disk: `App\Support\StoredFileUrl` re-signs a
     * private disk's URL on every read and ignores the stored one, so a value kept here would be
     * a stale signed URL nothing reads. It is nulled instead.
     *
     * `StoredFilesDiskMigrationTest` asserts this list covers every `*_disk` column in the
     * schema, so a new file-bearing table cannot be added without being migrated too.
     *
     * @var list<array{table: string, disk: string, paths: list<string>, urls: array<string, string>}>
     */
    private const TARGETS = [
        ['table' => 'assets', 'disk' => 'disk', 'paths' => ['path', 'thumbnail_path'], 'urls' => ['path' => 'url']],
        ['table' => 'brand_brains', 'disk' => 'persona_disk', 'paths' => ['persona_path'], 'urls' => ['persona_path' => 'persona_url']],
        ['table' => 'invoices', 'disk' => 'pdf_disk', 'paths' => ['pdf_path'], 'urls' => ['pdf_path' => 'pdf_url']],
        ['table' => 'ai_image_variants', 'disk' => 'preview_disk', 'paths' => ['preview_path'], 'urls' => []],
        ['table' => 'brand_brain_reference_assets', 'disk' => 'normalized_disk', 'paths' => ['normalized_path'], 'urls' => []],
        ['table' => 'organizations', 'disk' => 'brand_logo_disk', 'paths' => ['brand_logo_path'], 'urls' => []],
        // Data-export archives are short-lived, but a switch of disks mid-week would otherwise
        // break a download link an owner is still holding.
        ['table' => 'data_exports', 'disk' => 'disk', 'paths' => ['path'], 'urls' => []],
    ];

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) ($this->option('to') ?: config('assets.disk'));
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        if ($error = $this->validateDisks($from, $to)) {
            $this->components->error($error);

            return self::FAILURE;
        }

        $this->components->info(sprintf('%s %s → %s', $dryRun ? 'Would move' : 'Moving', $from, $to));

        $moved = $skipped = $failed = 0;

        foreach (self::TARGETS as $target) {
            [$m, $s, $f] = $this->migrateTable($target, $from, $to, $chunk, $dryRun);
            $moved += $m;
            $skipped += $s;
            $failed += $f;

            $this->line(sprintf('  %-30s moved %d, already there %d, failed %d', $target['table'], $m, $s, $f));
        }

        $this->newLine();
        $this->components->twoColumnDetail('Files moved', (string) $moved);
        $this->components->twoColumnDetail('Already on the target disk', (string) $skipped);
        $this->components->twoColumnDetail('Failed', (string) $failed);

        if ($failed > 0) {
            $this->components->warn('Some files did not move — see the log for each table and row id, then re-run.');

            return self::FAILURE;
        }

        if (! $dryRun) {
            $this->components->info('Source files were left in place. Remove them only once you have verified the move.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{table: string, disk: string, paths: list<string>, urls: array<string, string>}  $target
     * @return array{0: int, 1: int, 2: int}
     */
    private function migrateTable(array $target, string $from, string $to, int $chunk, bool $dryRun): array
    {
        $moved = $skipped = $failed = 0;
        $publicTarget = LocalAssetStorage::isPublicDisk($to);

        // The query builder, not Eloquent: these rows must be found regardless of org scoping or
        // soft deletes — a trashed client's files are still files on a disk.
        DB::table($target['table'])
            ->where($target['disk'], $from)
            ->where(function ($query) use ($target) {
                foreach ($target['paths'] as $path) {
                    $query->orWhereNotNull($path);
                }
            })
            ->orderBy('id')
            ->select(array_merge(['id'], $target['paths']))
            ->chunkById($chunk, function ($rows) use ($target, $from, $to, $dryRun, $publicTarget, &$moved, &$skipped, &$failed) {
                foreach ($rows as $row) {
                    /** @var array<string, mixed> $values */
                    $values = (array) $row;
                    $id = (int) ($values['id'] ?? 0);

                    try {
                        [$m, $s] = $this->migrateRow($target, $id, $values, $from, $to, $dryRun, $publicTarget);
                        $moved += $m;
                        $skipped += $s;
                    } catch (Throwable $e) {
                        $failed++;

                        Log::error('storage:migrate-disk could not move a row', [
                            'table' => $target['table'],
                            'id' => $id,
                            'from' => $from,
                            'to' => $to,
                            'exception' => $e::class,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return [$moved, $skipped, $failed];
    }

    /**
     * Copy one row's files, then repoint the row. The disk column is flipped last, so a crash
     * mid-copy leaves the row pointing at files that are still there.
     *
     * @param  array{table: string, disk: string, paths: list<string>, urls: array<string, string>}  $target
     * @param  array<string, mixed>  $values  the selected columns of this row
     * @return array{0: int, 1: int} moved, already-present
     */
    private function migrateRow(array $target, int $id, array $values, string $from, string $to, bool $dryRun, bool $publicTarget): array
    {
        $moved = $skipped = 0;
        $update = [];

        foreach ($target['paths'] as $column) {
            $path = $values[$column] ?? null;

            if (blank($path)) {
                continue;
            }

            $path = (string) $path;

            if (Storage::disk($to)->exists($path)) {
                // A re-run after an interrupted pass, or a file both disks already share.
                $skipped++;
            } elseif ($dryRun) {
                $moved++;
            } else {
                $stream = Storage::disk($from)->readStream($path);

                if (! is_resource($stream)) {
                    throw new RuntimeException("Missing on the {$from} disk: {$path}");
                }

                // Flysystem takes ownership of the handle and closes it, so this must not.
                Storage::disk($to)->writeStream($path, $stream);

                $moved++;
            }

            if ($urlColumn = $target['urls'][$column] ?? null) {
                $update[$urlColumn] = $publicTarget ? Storage::disk($to)->url($path) : null;
            }
        }

        if (! $dryRun && ($moved > 0 || $skipped > 0)) {
            $update[$target['disk']] = $to;
            DB::table($target['table'])->where('id', $id)->update($update);
        }

        return [$moved, $skipped];
    }

    /**
     * Both disks have to exist and differ, and an `s3` target has to actually be configured —
     * otherwise the run writes nothing and reports success.
     */
    private function validateDisks(string $from, string $to): ?string
    {
        foreach (['from' => $from, 'to' => $to] as $option => $disk) {
            if (blank($disk)) {
                return "--{$option} is required.";
            }

            if (! is_array(config("filesystems.disks.{$disk}"))) {
                return "There is no \"{$disk}\" disk in config/filesystems.php (--{$option}).";
            }
        }

        if ($from === $to) {
            return "--from and --to are both \"{$from}\"; there is nothing to move.";
        }

        if (config("filesystems.disks.{$to}.driver") === 's3' && blank(config("filesystems.disks.{$to}.bucket"))) {
            return "The \"{$to}\" disk has no bucket configured — set the AWS_* environment variables first.";
        }

        return null;
    }
}
