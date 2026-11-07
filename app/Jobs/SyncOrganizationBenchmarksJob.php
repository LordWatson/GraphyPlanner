<?php

namespace App\Jobs;

use App\Actions\Benchmarks\SyncOrganizationBenchmarksAction;
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
 * Step GTM-4.1 — one organization's nightly competitor sweep, on the reports queue so it never
 * sits in front of a publish. Unique per org, so a manual refresh can't run alongside the nightly
 * one and double the reads against Meta.
 */
class SyncOrganizationBenchmarksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $organizationId, public readonly bool $force = false)
    {
        $this->onQueue(Queues::REPORTS);
    }

    public function uniqueId(): string
    {
        return (string) $this->organizationId;
    }

    public function handle(SyncOrganizationBenchmarksAction $sync): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization !== null) {
            $sync($organization, $this->force);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Benchmark sync job failed', ['org_id' => $this->organizationId, 'error' => $exception->getMessage()]);
    }
}
