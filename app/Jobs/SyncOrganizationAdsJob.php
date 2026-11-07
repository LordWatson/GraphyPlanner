<?php

namespace App\Jobs;

use App\Actions\Analytics\SyncOrganizationAdsAction;
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
 * Step GTM-5.1 — one organization's nightly ad-spend sweep, on the reports queue and unique per
 * org so it can never run twice at once.
 */
class SyncOrganizationAdsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $organizationId)
    {
        $this->onQueue(Queues::REPORTS);
    }

    public function uniqueId(): string
    {
        return (string) $this->organizationId;
    }

    public function handle(SyncOrganizationAdsAction $sync): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization !== null) {
            $sync($organization);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Ads sync job failed', ['org_id' => $this->organizationId, 'error' => $exception->getMessage()]);
    }
}
