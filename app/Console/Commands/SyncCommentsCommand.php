<?php

namespace App\Console\Commands;

use App\Jobs\SyncOrganizationCommentsJob;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncCommentsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'inbox:sync-comments {--org= : Only this organization id} {--force : Ignore the minimum interval between reads}';

    /**
     * @var string
     */
    protected $description = 'Step M-9.1: queue a social-inbox comment sync (recent published posts) per active organization';

    public function handle(): int
    {
        $queued = 0;

        Organization::query()
            ->active()
            ->whereNotNull('upload_post_key')
            ->when($this->option('org'), fn ($query, $id) => $query->whereKey((int) $id))
            ->orderBy('id')
            ->each(function (Organization $organization) use (&$queued) {
                SyncOrganizationCommentsJob::dispatch($organization->id, (bool) $this->option('force'));
                $queued++;
            });

        Log::info('Inbox comment sync queued', ['organizations' => $queued]);
        $this->info("Queued inbox comment sync for {$queued} organization(s).");

        return self::SUCCESS;
    }
}
