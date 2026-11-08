<?php

namespace App\Console\Commands;

use App\Http\Middleware\IdempotentApiRequest;
use App\Models\ApiIdempotencyKey;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Console\Command;

/**
 * Keeps the idempotency table to its retention window.
 *
 * One row per idempotent write, kept only long enough to answer a retry (see
 * `IdempotentApiRequest::RETENTION_HOURS`). Without this the table grows forever and the stored
 * response bodies with it.
 */
class PruneApiIdempotencyKeysCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'api:prune-idempotency-keys {--hours= : Override the retention window}';

    /**
     * @var string
     */
    protected $description = 'Delete API idempotency keys past their retention window';

    public function handle(): int
    {
        $hours = $this->option('hours');
        $hours = is_numeric($hours) ? (int) $hours : IdempotentApiRequest::RETENTION_HOURS;

        // Every organization's rows, so this runs the same from the scheduler as from a console
        // where nobody is signed in.
        $deleted = ApiIdempotencyKey::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('created_at', '<', now()->subHours($hours))
            ->delete();

        $this->info("Pruned {$deleted} idempotency key(s) older than {$hours}h.");

        return self::SUCCESS;
    }
}
