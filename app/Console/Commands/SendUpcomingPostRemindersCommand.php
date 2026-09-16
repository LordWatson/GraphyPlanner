<?php

namespace App\Console\Commands;

use App\Actions\Posts\SendUpcomingPostReminderEmailsAction;
use Illuminate\Console\Command;

class SendUpcomingPostRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'posts:send-upcoming-reminders
        {--days=3 : How many days ahead to look for posts due to publish}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Email organization users about posts scheduled to publish soon';

    public function handle(SendUpcomingPostReminderEmailsAction $action): int
    {
        $days = (int) $this->option('days');

        $count = $action($days);

        $this->info("Sent reminder emails for {$count} upcoming post target(s).");

        return self::SUCCESS;
    }
}
