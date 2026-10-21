<?php

namespace App\Actions\Dashboard;

use App\Enums\ClientHealth;
use App\Models\Client;
use App\Models\User;

class GetClientHealthSummaryAction
{
    /**
     * Build the dashboard's "Client health" card: every org client's computed health status
     * (`Client::health()`, backed by `ClientHealthService`), post count, and retainer, ranked
     * Red first, then Amber, then Green, so the riskiest clients surface at the top.
     * Step M-3.4: reasons as `$viewer` may read them (`Client::healthFor()`).
     *
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(int $orgId, int $limit = 8, ?User $viewer = null): array
    {
        // Step I-2.5: health is materialized, so the ranking and the limit happen in SQL.
        $clients = Client::query()
            ->where('org_id', $orgId)
            ->withCount('posts')
            ->orderByRaw(
                'CASE health_status WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END',
                [ClientHealth::Red->value, ClientHealth::Amber->value, ClientHealth::Green->value],
            )
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $rows = $clients->map(function (Client $client) use ($viewer) {
            $health = $client->healthFor($viewer);

            return [
                'id' => $client->id,
                'name' => $client->name,
                'post_count' => $client->posts_count,
                'retainer_amount' => $client->retainer_amount !== null ? (float) $client->retainer_amount : null,
                'billing_cycle_label' => $client->billing_cycle?->label(),
                'status' => $health['status']->value,
                'status_label' => $health['status']->label(),
                'reason' => $health['reason'],
                'reasons' => $health['reasons'],
            ];
        });

        $order = [ClientHealth::Red->value => 0, ClientHealth::Amber->value => 1, ClientHealth::Green->value => 2];

        return $rows
            ->sortBy(fn (array $row) => $order[$row['status']] ?? 3)
            ->values()
            ->take($limit)
            ->all();
    }
}
