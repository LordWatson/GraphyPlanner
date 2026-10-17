<?php

namespace App\Actions\Analytics;

use App\Enums\ClientStatus;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;

/**
 * Step 17.4 — what the analytics filter bar (and the report builder) can offer: the org's own
 * clients, campaigns and the platforms it has accounts on. Never another organization's rows.
 */
class GetAnalyticsFilterOptionsAction
{
    /**
     * @return array{clients: list<array{value: int, label: string, archived: bool}>, campaigns: list<array{value: int, label: string, client_id: int, client: string}>, platforms: list<array{value: string, label: string}>}
     */
    public function __invoke(Organization $organization): array
    {
        $clients = Client::query()
            ->where('org_id', $organization->id)
            ->orderBy('name')
            ->get(['id', 'name', 'status']);

        $clientNames = $clients->pluck('name', 'id');

        $platforms = SocialAccount::query()
            ->where('org_id', $organization->id)
            ->distinct()
            ->pluck('platform')
            ->map(fn ($platform) => $platform instanceof Platform ? $platform->value : (string) $platform)
            ->all();

        return [
            'clients' => array_values($clients
                ->map(fn (Client $client) => [
                    'value' => $client->id,
                    'label' => $client->name,
                    'archived' => $client->status === ClientStatus::Archived,
                ])
                ->all()),
            'campaigns' => array_values(Campaign::query()
                ->where('org_id', $organization->id)
                ->orderBy('name')
                ->get(['id', 'name', 'client_id'])
                ->map(fn (Campaign $campaign) => [
                    'value' => $campaign->id,
                    'label' => $campaign->name,
                    'client_id' => $campaign->client_id,
                    'client' => (string) ($clientNames[$campaign->client_id] ?? ''),
                ])
                ->all()),
            'platforms' => array_map(
                fn (Platform $platform) => ['value' => $platform->value, 'label' => $platform->label()],
                array_values(array_filter(Platform::cases(), fn (Platform $platform) => in_array($platform->value, $platforms, true))),
            ),
        ];
    }

    /**
     * Step 17.8 — who a scheduled report can be emailed to: the organization's staff (never client
     * reviewers), by name.
     *
     * @return list<array{value: int, label: string, role: string}>
     */
    public function recipients(Organization $organization): array
    {
        return array_values(User::query()
            ->where('org_id', $organization->id)
            ->whereIn('role', [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer])
            ->orderBy('name')
            ->get(['id', 'name', 'role'])
            ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name, 'role' => $user->role->label()])
            ->all());
    }
}
