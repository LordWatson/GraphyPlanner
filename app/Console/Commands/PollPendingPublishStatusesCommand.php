<?php

namespace App\Console\Commands;

use App\Actions\Publishing\PollPendingPublishStatusesAction;
use App\Actions\Publishing\SyncPublishStatusAction;
use App\Contracts\PublishAdapter;
use Illuminate\Console\Command;

/**
 * Step 1.5 fallback poll — a scheduled safety net for posts stuck in `publishing` because the
 * Upload-Post webhook never arrived (or was rejected/dropped in transit).
 */
class PollPendingPublishStatusesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'publishing:poll-pending-statuses
        {--minutes=5 : Only poll targets that have been pending for at least this many minutes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll the publish vendor for the outcome of pending targets whose webhook never arrived';

    public function handle(
        PollPendingPublishStatusesAction $action,
        PublishAdapter $adapter,
        SyncPublishStatusAction $sync,
    ): int {
        $minutes = (int) $this->option('minutes');

        $checked = $action($adapter, $sync, $minutes);

        $this->info("Polled {$checked} pending publish target(s).");

        return self::SUCCESS;
    }
}
