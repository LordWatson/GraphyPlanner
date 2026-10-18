<?php

namespace App\Console\Commands;

use App\Actions\Campaigns\SendDeliverableDueRemindersAction;
use Illuminate\Console\Command;

/**
 * Step C-4.4 — daily: tells assignees which campaign deliverables are due tomorrow or just went
 * overdue.
 */
class SendDeliverableDueRemindersCommand extends Command
{
    protected $signature = 'campaigns:send-due-reminders';

    protected $description = 'Notify assignees about campaign deliverables due tomorrow or overdue';

    public function handle(SendDeliverableDueRemindersAction $action): int
    {
        $sent = $action();

        $this->info("Due tomorrow: {$sent['due_tomorrow']}, overdue: {$sent['overdue']}.");

        return self::SUCCESS;
    }
}
