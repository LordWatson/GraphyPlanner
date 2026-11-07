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
 * stored file it owned — its clients' assets, invoice and persona PDFs and AI previews, plus the
 * organization's own white-label logo — through `AssetStorage`, and finally hard-deletes the
 * organization so the database cascades remove its rows. Idempotent: an organization that was
 * restored or is already gone is left alone.
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

        // Both owners: the clients' files, and the organization's own (the white-label logo, which
        // belongs to no client and so was previously left in storage for good).
        $files = $inventory->forClients($clientIds)
            ->merge($inventory->forOrganizations([$organization->id]))
            ->unique(fn (array $file) => $file['disk'].'|'.$file['path'])
            ->values();

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

        DB::transaction(function () use ($organization, $clientIds) {
            // `invoices.client_id` is `NO ACTION`, which the migration that set it believed was
            // deferred to the end of the statement — so cascading `organizations → clients →
            // invoices` in one delete was expected to work. InnoDB treats `NO ACTION` as a synonym
            // for `RESTRICT` and checks it immediately, so on MySQL the cascade hit
            // `SQLSTATE 23000 / 1451` and this job failed every retry, leaving the organization
            // soft-deleted forever with its files undeleted. SQLite does defer, which is why the
            // test suite never saw it.
            //
            // Removing an organization's invoices is the intended behaviour (a *client* purge
            // still refuses while invoices exist — see `PurgeClientAction`); they just have to go
            // first, explicitly. `invoice_items` cascades from them and `brand_deals.invoice_id`
            // is `nullOnDelete`, so nothing else needs ordering.
            DB::table('invoices')
                ->where('org_id', $organization->id)
                ->orWhereIn('client_id', $clientIds)
                ->delete();

            $organization->forceDelete();
        });

        Log::warning('Organization purged', [
            'org_id' => $this->organizationId,
            'files_deleted' => $files->count() - $failed,
            'files_failed' => $failed,
        ]);
    }
}
