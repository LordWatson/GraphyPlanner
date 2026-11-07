<?php

namespace App\Console\Commands;

use App\Jobs\SyncOrganizationAdsJob;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Step GTM-5.1 — nightly: queue one ad-spend sweep per organization that has ad accounts. Orgs
 * without any are never queued, so this costs nothing for everybody who doesn't report on ads.
 */
class SyncAdsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ads:sync {--org= : Only this organization id}';

    /**
     * @var string
     */
    protected $description = 'Step GTM-5.1: queue a read-only paid-ads metrics sync per organization with ad accounts';

    public function handle(): int
    {
        $queued = 0;

        Organization::query()
            ->active()
            ->whereHas('adAccounts')
            ->when($this->option('org'), fn ($query, $id) => $query->whereKey((int) $id))
            ->orderBy('id')
            ->each(function (Organization $organization) use (&$queued) {
                SyncOrganizationAdsJob::dispatch($organization->id);
                $queued++;
            });

        Log::info('Ads sync queued', ['organizations' => $queued]);
        $this->info("Queued an ads sync for {$queued} organization(s).");

        return self::SUCCESS;
    }
}
