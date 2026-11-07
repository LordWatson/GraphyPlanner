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
        $force = (bool) $this->option('force');
        $single = $this->option('org');
        $spread = max(0, (int) config('analytics.sync_spread_minutes', 20));

        Organization::query()
            ->active()
            ->whereNotNull('upload_post_key')
            ->when($single, fn ($query, $id) => $query->whereKey((int) $id))
            ->orderBy('id')
            ->each(function (Organization $organization) use (&$queued, $force, $single, $spread) {
                // Spread the sweep instead of dropping every organization onto the queue at once.
                // This runs every 30 minutes; at a few hundred organizations, each making several
                // vendor calls, firing them together means the whole sweep is contending with
                // itself and anything user-facing on the same queue waits behind it. A stable
                // per-org offset also means an organization is read at roughly the same moment
                // each cycle, which keeps the `min_interval_hours` guard predictable.
                //
                // `--org` is a person asking for one now, so it is not delayed.
                $delay = $single || $spread === 0
                    ? null
                    : now()->addSeconds(($organization->id % $spread) * 60);

                SyncOrganizationEngagementJob::dispatch($organization->id, $force)->delay($delay);
                $queued++;
            });

        Log::info('Engagement sync queued', ['organizations' => $queued, 'spread_minutes' => $single ? 0 : $spread]);
        $this->info("Queued engagement sync for {$queued} organization(s).");

        return self::SUCCESS;
    }
}
