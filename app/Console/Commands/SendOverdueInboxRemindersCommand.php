<?php

namespace App\Console\Commands;

use App\Jobs\SendOverdueInboxRemindersJob;
use Illuminate\Console\Command;

class SendOverdueInboxRemindersCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'inbox:send-overdue-reminders';

    /**
     * @var string
     */
    protected $description = 'Nudge the assignee or studio about inbox threads past the reply SLA (Step M-9.2)';

    public function handle(): int
    {
        // Run inline so the scheduled run (and the heartbeat) reflect the work completing.
        SendOverdueInboxRemindersJob::dispatchSync();

        $this->info('Overdue inbox reminders dispatched.');

        return self::SUCCESS;
    }
}
