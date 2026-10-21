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
 * `clients.health_status/health_reason/health_reasons/health_computed_at`, recording a `client_health_history`
 * row whenever the status or reason actually changes.
 *
 * Fired from the events the rules depend on: the client's own attributes (`Client::saved`), a
 * social account's connection status (`SocialAccount` saved/deleted/restored), and the nightly
 * `clients:recompute-health` sweep (which also catches a token crossing into its expiry window). The write is quiet and leaves `updated_at` alone, so a
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
            // Step M-2.7: plus what the token-expiry rule reads.
            ->get(['id', 'client_id', 'platform', 'handle', 'connection_status', 'meta_access_token', 'meta_access_token_expires_at']));

        $result = $this->health->compute($client);
        $changed = $client->health_status !== $result['status'] || $client->health_reason !== $result['reason'];
        // Step M-3.4: the full reason list is kept current too (history records headline changes).
        $reasonsChanged = $changed || ($client->health_reasons ?? []) !== $result['reasons'];

        DB::transaction(function () use ($client, $result, $changed) {
            $client->timestamps = false;
            $client->forceFill([
                'health_status' => $result['status'],
                'health_reason' => $result['reason'],
                'health_reasons' => $result['reasons'],
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

        if ($reasonsChanged) {
            AnalyticsCache::bust($client->org_id);
        }

        if ($changed) {

            Log::info('Client health changed', [
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'health' => $result['status']->value,
            ]);
        }

        return $changed;
    }
}
