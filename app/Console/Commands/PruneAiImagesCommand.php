<?php

namespace App\Console\Commands;

use App\Actions\AI\Images\PruneAiImagesAction;
use Illuminate\Console\Command;

class PruneAiImagesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ai-images:prune {--days= : Override ai.images.prune_after_days}';

    /**
     * @var string
     */
    protected $description = 'Delete files of AI Image Studio variants discarded or never selected (attached images are kept)';

    public function handle(PruneAiImagesAction $action): int
    {
        $days = $this->option('days');
        $result = $action(is_numeric($days) ? (int) $days : null);

        $this->info("Pruned {$result['variants']} AI image variant(s), {$result['files']} file(s).");

        return self::SUCCESS;
    }
}
