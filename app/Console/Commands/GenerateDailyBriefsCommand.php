<?php

namespace App\Console\Commands;

use App\Actions\AI\GenerateDailyBriefsAction;
use Illuminate\Console\Command;

class GenerateDailyBriefsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:generate-daily-briefs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the nightly AI Daily Brief (trends + post ideas) for every active client';

    public function handle(GenerateDailyBriefsAction $action): int
    {
        $count = $action();

        $this->info("Generated {$count} daily brief(s).");

        return self::SUCCESS;
    }
}
