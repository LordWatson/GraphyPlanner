<?php

namespace App\Jobs;

use App\Contracts\AssetStorage;
use App\Models\Organization;
use App\Services\StoredFileInventory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step I-1.5 — the second half of a super admin's organization delete. `DeleteOrganizationAction`
 * soft-deletes the organization (it vanishes everywhere at once); this job then removes every
 * stored file its clients owned (assets, invoice and persona PDFs, AI previews) through
 * `AssetStorage`, and finally hard-deletes the organization so the database cascades remove its
 * rows. Idempotent: an organization that was restored or is already gone is left alone.
 */
class PurgeOrganizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public readonly int $organizationId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(AssetStorage $storage, StoredFileInventory $inventory): void
    {
        $organization = Organization::withTrashed()->find($this->organizationId);

        if (! $organization || ! $organization->trashed()) {
            Log::info('PurgeOrganizationJob: organization missing or not deleted, skipping', ['org_id' => $this->organizationId]);

            return;
        }

        $clientIds = DB::table('clients')->where('org_id', $organization->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $files = $inventory->forClients($clientIds);
        $failed = 0;

        foreach ($files as $file) {
            try {
                $storage->delete($file['disk'], $file['path']);
            } catch (Throwable $e) {
                $failed++;
                Log::warning('PurgeOrganizationJob: could not delete a stored file', [
                    'org_id' => $organization->id,
                    'disk' => $file['disk'],
                    'path' => $file['path'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        DB::transaction(fn () => $organization->forceDelete());

        Log::warning('Organization purged', [
            'org_id' => $this->organizationId,
            'files_deleted' => $files->count() - $failed,
            'files_failed' => $failed,
        ]);
    }
}
