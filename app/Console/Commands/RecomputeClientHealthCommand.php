<?php

namespace App\Console\Commands;

use App\Actions\Clients\RecomputeClientHealthAction;
use App\Models\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecomputeClientHealthCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'clients:recompute-health';

    /**
     * @var string
     */
    protected $description = 'Step I-2.5: re-materialize every client\'s health (the nightly safety sweep behind the event triggers)';

    public function handle(RecomputeClientHealthAction $recompute): int
    {
        $checked = 0;
        $changed = 0;

        Client::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(200, function ($clients) use ($recompute, &$checked, &$changed) {
                foreach ($clients as $client) {
                    $checked++;
                    $changed += $recompute($client) ? 1 : 0;
                }
            });

        Log::info('Client health sweep finished', ['checked' => $checked, 'changed' => $changed]);
        $this->info("Recomputed health for {$checked} client(s); {$changed} changed.");

        return self::SUCCESS;
    }
}
