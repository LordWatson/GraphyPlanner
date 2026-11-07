<?php

namespace App\Console\Commands;

use App\Jobs\SyncOrganizationBenchmarksJob;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Step GTM-4.1 — nightly: queue one competitor sweep per organization that has tracked profiles.
 * Orgs without any are never queued, so the sweep costs nothing for everybody who doesn't use it.
 */
class SyncBenchmarksCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'benchmarks:sync {--org= : Only this organization id} {--force : Re-read profiles already read today}';

    /**
     * @var string
     */
    protected $description = 'Step GTM-4.1: queue a competitor benchmark sync per organization with tracked profiles';

    public function handle(): int
    {
        $queued = 0;

        Organization::query()
            ->active()
            ->whereHas('trackedProfiles')
            ->when($this->option('org'), fn ($query, $id) => $query->whereKey((int) $id))
            ->orderBy('id')
            ->each(function (Organization $organization) use (&$queued) {
                SyncOrganizationBenchmarksJob::dispatch($organization->id, (bool) $this->option('force'));
                $queued++;
            });

        Log::info('Benchmark sync queued', ['organizations' => $queued]);
        $this->info("Queued a benchmark sync for {$queued} organization(s).");

        return self::SUCCESS;
    }
}
