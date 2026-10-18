<?php

namespace App\Actions\Clients;

use App\Enums\ClientHealth;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Step I-2.5 — the clients roster, paginated and searched/filtered/sorted in SQL. Health comes
 * from the materialized `clients.health_status`, so it sorts and filters like any column.
 *
 * Search matches name, legal name and tags (`tags` is a JSON array; a LIKE over its text works
 * on MySQL and SQLite alike). Sorting by retainer is only honoured for viewers who may see billing
 * — anyone else gets name order, so the order never leaks the amounts.
 */
class ListClientsAction
{
    /**
     * @param  array{archived: bool, search: string|null, status: string|null, health: string|null, owner: int|null, tag: string|null, sort: string, direction: string, per_page: int}  $filters
     * @return LengthAwarePaginator<int, Client>
     */
    public function __invoke(User $user, array $filters, bool $canViewBilling): LengthAwarePaginator
    {
        $direction = $filters['direction'] === 'desc' ? 'desc' : 'asc';

        $query = Client::query()
            ->where('clients.org_id', $user->org_id)
            ->when($filters['archived'], fn (Builder $query) => $query->onlyTrashed()
                ->withExists(['invoices as has_invoices' => fn (Builder $invoices) => $invoices->withoutGlobalScope(SoftDeletingScope::class)]))
            ->with('owner:id,name')
            ->when($filters['search'], function (Builder $query, string $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';

                $query->where(fn (Builder $query) => $query
                    ->where('clients.name', 'like', $like)
                    ->orWhere('clients.legal_name', 'like', $like)
                    ->orWhere('clients.tags', 'like', $like));
            })
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('clients.status', $status))
            ->when($filters['health'], fn (Builder $query, string $health) => $query->where('clients.health_status', $health))
            ->when($filters['owner'], fn (Builder $query, int $owner) => $query->where('clients.owner_user_id', $owner))
            ->when($filters['tag'], fn (Builder $query, string $tag) => $query->where('clients.tags', 'like', '%"'.addcslashes($tag, '%_\\"').'"%'));

        match ($filters['sort'] === 'retainer' && ! $canViewBilling ? 'name' : $filters['sort']) {
            'health' => $query->orderByRaw(
                'CASE clients.health_status WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END '.$direction,
                [ClientHealth::Red->value, ClientHealth::Amber->value, ClientHealth::Green->value],
            ),
            'owner' => $query->orderBy(
                User::query()->withoutGlobalScopes()->select('name')->whereColumn('users.id', 'clients.owner_user_id')->limit(1),
                $direction,
            ),
            'start_date' => $query->orderBy('clients.start_date', $direction),
            'retainer' => $query->orderBy('clients.retainer_amount', $direction),
            default => $query->orderBy('clients.name', $direction),
        };

        return $query
            ->orderBy('clients.name')
            ->orderBy('clients.id')
            ->paginate($filters['per_page'])
            ->withQueryString();
    }

    /**
     * Roster-wide health split for the header (ignores the filters, like the archive count).
     *
     * @return array{red: int, amber: int, green: int}
     */
    public function healthCounts(User $user): array
    {
        $counts = Client::query()
            ->where('org_id', $user->org_id)
            ->toBase()
            ->selectRaw('health_status, COUNT(*) as aggregate')
            ->groupBy('health_status')
            ->pluck('aggregate', 'health_status');

        return [
            'red' => (int) ($counts[ClientHealth::Red->value] ?? 0),
            'amber' => (int) ($counts[ClientHealth::Amber->value] ?? 0),
            'green' => (int) ($counts[ClientHealth::Green->value] ?? 0),
        ];
    }
}
