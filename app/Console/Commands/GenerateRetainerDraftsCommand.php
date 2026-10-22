<?php

namespace App\Console\Commands;

use App\Actions\Invoices\GenerateRetainerDraftsAction;
use Illuminate\Console\Command;

class GenerateRetainerDraftsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'invoices:generate-retainer-drafts';

    /**
     * @var string
     */
    protected $description = 'Step M-4.3: draft this period\'s retainer invoices for opted-in clients (idempotent; never finalized or sent)';

    public function handle(GenerateRetainerDraftsAction $action): int
    {
        $created = $action();

        $this->info("Created {$created} retainer draft(s).");

        return self::SUCCESS;
    }
}
