<?php

namespace App\Actions\Clients;

use App\Models\Client;
use App\Models\ClientHealthHistory;
use App\Models\Scopes\OrganizationScope;
use App\Services\Analytics\AnalyticsCache;
use App\Services\ClientHealthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step I-2.5 — re-run `ClientHealthService` for one client and store the result on
 * `clients.health_status/health_reason/health_computed_at`, recording a `client_health_history`
 * row whenever the status or reason actually changes.
 *
 * Fired from the events the rules depend on: the client's own attributes (`Client::saved`), a
 * social account's connection status (`SocialAccount` saved/deleted/restored), and the nightly
 * `clients:recompute-health` sweep. The write is quiet and leaves `updated_at` alone, so a
 * recompute never looks like a user edit and never re-triggers itself.
 */
class RecomputeClientHealthAction
{
    public function __construct(private readonly ClientHealthService $health) {}

    /**
     * @return bool whether the stored health changed
     */
    public function __invoke(Client $client): bool
    {
        // Fresh accounts, unscoped: this can run for a job/webhook or after an account change.
        $client->setRelation('socialAccounts', $client->socialAccounts()
            ->withoutGlobalScope(OrganizationScope::class)
            ->get(['id', 'client_id', 'connection_status']));

        $result = $this->health->compute($client);
        $changed = $client->health_status !== $result['status'] || $client->health_reason !== $result['reason'];

        DB::transaction(function () use ($client, $result, $changed) {
            $client->timestamps = false;
            $client->forceFill([
                'health_status' => $result['status'],
                'health_reason' => $result['reason'],
                'health_computed_at' => Carbon::now(),
            ])->saveQuietly();
            $client->timestamps = true;

            if ($changed) {
                ClientHealthHistory::query()->create([
                    'client_id' => $client->id,
                    'health_status' => $result['status'],
                    'health_reason' => $result['reason'],
                    'created_at' => Carbon::now(),
                ]);
            }
        });

        if ($changed) {
            AnalyticsCache::bust($client->org_id);

            Log::info('Client health changed', [
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'health' => $result['status']->value,
            ]);
        }

        return $changed;
    }
}
