<?php

namespace App\Actions\Clients;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\Post;
use App\Models\User;
use App\Services\Publishing\VendorScheduleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step I-1.5 — "Delete client" is now *archive*: the client is marked `archived` and soft-deleted,
 * so it disappears from every list, its publishing and reminders pause, its portal and review
 * links stop working — but its posts, approvals, audit trail and invoices are all kept, and it
 * can be restored from the "Archived" filter. Permanent removal is `PurgeClientAction`.
 */
class ArchiveClientAction
{
    public function __construct(private readonly VendorScheduleService $vendor) {}

    public function __invoke(Client $client, User $actor): Client
    {
        // Archiving pauses publishing (`PublishPostJob::pausedReason()`), so scheduled posts the
        // vendor is already holding are cancelled there; restoring re-sends them at their time.
        $released = $this->vendor->releaseScheduled(Post::query()->withoutOrgScope()->where('client_id', $client->id));

        DB::transaction(function () use ($client) {
            $client->update(['status' => ClientStatus::Archived]);
            $client->delete();
        });

        Log::info('Client archived', [
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'user_id' => $actor->id,
            'released_scheduled_posts' => $released,
        ]);

        return $client;
    }
}
