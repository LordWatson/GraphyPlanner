<?php

namespace App\Console\Commands;

use App\Actions\Notifications\SendNotificationDigestAction;
use Illuminate\Console\Command;

class SendNotificationDigestCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'notifications:send-digest';

    /**
     * @var string
     */
    protected $description = 'Email each user a digest of the day\'s notifications they kept in their digest (Step M-6.7)';

    public function handle(SendNotificationDigestAction $action): int
    {
        $count = $action();

        $this->info("Queued {$count} notification digest(s).");

        return self::SUCCESS;
    }
}
