<?php

namespace App\Console\Commands;

use App\Actions\Store\ExpireStaleOrdersAction;
use Illuminate\Console\Command;

class ExpireStaleOrdersCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'store:expire-orders';

    /**
     * @var string
     */
    protected $description = 'Cancel abandoned checkouts (releasing their booking slots) and release the authorisation on paid questions nobody answered in time';

    public function handle(ExpireStaleOrdersAction $action): int
    {
        $result = $action();

        $this->info("Cancelled {$result['cancelled']} abandoned checkout(s), released {$result['released']} unanswered question(s).");

        return self::SUCCESS;
    }
}
