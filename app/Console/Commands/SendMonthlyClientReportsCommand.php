<?php

namespace App\Console\Commands;

use App\Actions\Analytics\SendMonthlyClientReportsAction;
use Illuminate\Console\Command;

class SendMonthlyClientReportsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'analytics:send-client-reports';

    /**
     * @var string
     */
    protected $description = 'Step M-3.3: prepare last month\'s client reports (and publish the automatic ones) on the report day, per organization timezone';

    public function handle(SendMonthlyClientReportsAction $send): int
    {
        $totals = $send();

        $this->info("Prepared {$totals['prepared']} report(s), published {$totals['published']}, {$totals['failed']} failed.");

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
