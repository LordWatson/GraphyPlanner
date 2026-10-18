<?php

namespace App\Console\Commands;

use App\Actions\Analytics\SendScheduledOrganizationReportsAction;
use Illuminate\Console\Command;

class SendScheduledReportsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analytics:send-scheduled-reports';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Email every saved organization report whose weekly/monthly schedule is due';

    public function handle(SendScheduledOrganizationReportsAction $action): int
    {
        $count = $action();

        $this->info("Sent {$count} scheduled report(s).");

        return self::SUCCESS;
    }
}
