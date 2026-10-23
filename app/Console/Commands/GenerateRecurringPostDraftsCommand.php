<?php

namespace App\Console\Commands;

use App\Actions\Posts\GenerateRecurringPostDraftsAction;
use Illuminate\Console\Command;

class GenerateRecurringPostDraftsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'posts:generate-recurring-drafts';

    /**
     * @var string
     */
    protected $description = 'Step M-5.8: drop the next occurrence of each recurring post as a draft (idempotent; never scheduled)';

    public function handle(GenerateRecurringPostDraftsAction $action): int
    {
        $created = $action();

        $this->info("Created {$created} recurring post draft(s).");

        return self::SUCCESS;
    }
}
