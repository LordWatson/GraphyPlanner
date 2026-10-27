<?php

namespace App\Console\Commands;

use App\Actions\Push\SendNothingScheduledTomorrowPushAction;
use Illuminate\Console\Command;

/**
 * Step CG-1.3 — the daily "nothing scheduled tomorrow" web-push nudge for active business/creator
 * workspaces. Scheduled once a day (see `routes/console.php`); idempotent per org per local day.
 */
class SendNothingScheduledTomorrowPushCommand extends Command
{
    protected $signature = 'push:send-empty-tomorrow';

    protected $description = 'Nudge business workspaces with nothing scheduled for tomorrow';

    public function handle(SendNothingScheduledTomorrowPushAction $action): int
    {
        $count = $action();

        $this->info("Nudged {$count} workspace(s) with nothing scheduled tomorrow.");

        return self::SUCCESS;
    }
}
