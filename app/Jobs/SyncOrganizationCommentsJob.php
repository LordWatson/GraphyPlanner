<?php

namespace App\Jobs;

use App\Actions\Inbox\SyncOrganizationCommentsAction;
use App\Models\Organization;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step M-9.1 — one organization's hourly comment sweep, on the reports queue so it never sits in
 * front of a publish. Unique per org, so a manual refresh can't run alongside the scheduled one.
 */
class SyncOrganizationCommentsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 1800;

    public function __construct(public readonly int $organizationId, public readonly bool $force = false)
    {
        $this->onQueue(Queues::REPORTS);
    }

    public function uniqueId(): string
    {
        return (string) $this->organizationId;
    }

    public function handle(SyncOrganizationCommentsAction $sync): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $sync($organization, $this->force);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Inbox sync job failed', ['org_id' => $this->organizationId, 'error' => $exception->getMessage()]);
    }
}
