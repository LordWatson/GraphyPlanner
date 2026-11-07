<?php

namespace App\Jobs;

use App\Actions\Analytics\SyncOrganizationEngagementAction;
use App\Models\Organization;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step M-3.1 — one organization's nightly engagement sweep, on the reports queue so it never sits
 * in front of a publish. Unique per org, so a manual refresh can't run alongside the nightly one.
 */
class SyncOrganizationEngagementJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $organizationId, public readonly bool $force = false)
    {
        $this->onQueue(Queues::REPORTS);
    }

    public function uniqueId(): string
    {
        return (string) $this->organizationId;
    }

    public function handle(SyncOrganizationEngagementAction $sync): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $startedAt = microtime(true);
        $queriesBefore = count(DB::getQueryLog());

        $sync($organization, $this->force);

        // How long one organization's sweep actually takes, which is the number that decides
        // whether the 30-minute cycle can keep up: `orgs × seconds ÷ (processes × 1800)` above 1
        // means it cannot, and everything else on the reports queue waits behind it. Logged rather
        // than guessed — the guess in the original audit was the weakest part of it.
        Log::info('Engagement sync finished', [
            'org_id' => $organization->id,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'queries' => count(DB::getQueryLog()) - $queriesBefore,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Engagement sync job failed', ['org_id' => $this->organizationId, 'error' => $exception->getMessage()]);
    }
}
