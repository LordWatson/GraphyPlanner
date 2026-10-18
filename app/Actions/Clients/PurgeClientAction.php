<?php

namespace App\Actions\Clients;

use App\Contracts\AssetStorage;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Services\StoredFileInventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Step I-1.5 — permanently delete an (archived) client: Owner-only, and blocked while the client
 * has any invoice (invoices are legal records — the database enforces it too, via the
 * `invoices.client_id` foreign key). Every row that belongs to the client goes with it through the
 * existing cascades, and its stored files (assets, persona PDF, AI previews, …) are removed via
 * `AssetStorage` once the delete has committed, so a failed purge never loses files.
 */
class PurgeClientAction
{
    public function __construct(
        private readonly AssetStorage $storage,
        private readonly StoredFileInventory $inventory = new StoredFileInventory,
    ) {}

    public function __invoke(Client $client, User $actor): void
    {
        if (Invoice::withTrashed()->where('client_id', $client->id)->exists()) {
            throw ValidationException::withMessages([
                'client' => __('This client has invoices, which must be kept. Archive it instead.'),
            ]);
        }

        $files = $this->inventory->forClients([$client->id]);

        DB::transaction(function () use ($client) {
            // Portal logins exist only for this client — `users.client_id` is `nullOnDelete`, so
            // left alone they'd survive as client-less accounts.
            User::query()
                ->where('client_id', $client->id)
                ->where('role', Role::ClientReviewer)
                ->delete();

            // A hard delete, so the database cascades (posts, targets, logs, assets, campaigns, …)
            // run — soft-deleted children included.
            $client->forceDelete();
        });

        $failed = 0;

        foreach ($files as $file) {
            try {
                $this->storage->delete($file['disk'], $file['path']);
            } catch (Throwable $e) {
                $failed++;
                Log::warning('Client purge: could not delete a stored file', [
                    'client_id' => $client->id,
                    'disk' => $file['disk'],
                    'path' => $file['path'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::warning('Client purged', [
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'user_id' => $actor->id,
            'files_deleted' => $files->count() - $failed,
            'files_failed' => $failed,
        ]);
    }
}
