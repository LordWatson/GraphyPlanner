<?php

namespace App\Console\Commands;

use App\Jobs\SyncOrganizationEngagementJob;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncEngagementCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'analytics:sync-engagement {--org= : Only this organization id} {--force : Ignore the minimum interval between reads}';

    /**
     * @var string
     */
    protected $description = 'Step M-3.1: queue an engagement metrics sync (accounts + published posts) per active organization';

    public function handle(): int
    {
        $queued = 0;

        Organization::query()
            ->active()
            ->whereNotNull('upload_post_key')
            ->when($this->option('org'), fn ($query, $id) => $query->whereKey((int) $id))
            ->orderBy('id')
            ->each(function (Organization $organization) use (&$queued) {
                SyncOrganizationEngagementJob::dispatch($organization->id, (bool) $this->option('force'));
                $queued++;
            });

        Log::info('Engagement sync queued', ['organizations' => $queued]);
        $this->info("Queued engagement sync for {$queued} organization(s).");

        return self::SUCCESS;
    }
}
