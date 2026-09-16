<?php

namespace App\Actions\Dashboard;

use App\Enums\ClientHealth;
use App\Models\Client;

class GetClientHealthSummaryAction
{
    /**
     * Build the dashboard's "Client health" card: every org client's computed health status
     * (`Client::health()`, backed by `ClientHealthService`), post count, and retainer, ranked
     * Red first, then Amber, then Green, so the riskiest clients surface at the top.
     *
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(int $orgId, int $limit = 8): array
    {
        $clients = Client::query()
            ->where('org_id', $orgId)
            ->withCount('posts')
            ->get();

        $rows = $clients->map(function (Client $client) {
            $health = $client->health();

            return [
                'id' => $client->id,
                'name' => $client->name,
                'post_count' => $client->posts_count,
                'retainer_amount' => $client->retainer_amount !== null ? (float) $client->retainer_amount : null,
                'billing_cycle_label' => $client->billing_cycle?->label(),
                'status' => $health['status']->value,
                'status_label' => $health['status']->label(),
                'reason' => $health['reason'],
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
