<?php

namespace App\Console\Commands;

use App\Actions\Push\SendPostPushRemindersAction;
use Illuminate\Console\Command;

/**
 * Step B-7.4 — fires the "your post goes out in ~30 minutes" web-push reminder. Scheduled every
 * five minutes (see `routes/console.php`); idempotent via `post_targets.push_reminder_sent_at`.
 */
class SendPostPushRemindersCommand extends Command
{
    protected $signature = 'push:send-post-reminders';

    protected $description = 'Send web-push reminders for posts about to publish';

    public function handle(SendPostPushRemindersAction $action): int
    {
        $count = $action();

        $this->info("Sent push reminders for {$count} upcoming post target(s).");

        return self::SUCCESS;
    }
}
