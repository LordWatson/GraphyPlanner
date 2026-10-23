<?php

namespace App\Console\Commands;

use App\Jobs\SendOverdueApprovalRemindersJob;
use Illuminate\Console\Command;

class SendOverdueApprovalRemindersCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'approvals:send-overdue-reminders';

    /**
     * @var string
     */
    protected $description = 'Nudge the studio and the client about posts stuck past the approval SLA (Step M-6.5)';

    public function handle(): int
    {
        // Run inline so the scheduled run (and the heartbeat) reflect the work completing.
        SendOverdueApprovalRemindersJob::dispatchSync();

        $this->info('Overdue approval reminders dispatched.');

        return self::SUCCESS;
    }
}
