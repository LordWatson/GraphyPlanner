<?php

namespace App\Console\Commands;

use App\Actions\Grids\PruneGridPlansAction;
use Illuminate\Console\Command;

class PruneGridPlansCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'grids:prune {--months= : Override grids.prune_archived_after_months}';

    /**
     * @var string
     */
    protected $description = 'Soft-delete archived grid plans older than the retention window (split slices posts still use are kept)';

    public function handle(PruneGridPlansAction $action): int
    {
        $months = $this->option('months');
        $result = $action(is_numeric($months) ? (int) $months : null);

        $this->info("Pruned {$result['plans']} archived grid plan(s), {$result['assets']} unused split image(s).");

        return self::SUCCESS;
    }
}
