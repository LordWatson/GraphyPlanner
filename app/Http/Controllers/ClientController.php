<?php

namespace App\Http\Controllers;

use App\Actions\Clients\ArchiveClientAction;
use App\Actions\Clients\BuildClientPageHeaderAction;
use App\Actions\Clients\CreateClientAction;
use App\Actions\Clients\ListClientsAction;
use App\Actions\Clients\PurgeClientAction;
use App\Actions\Clients\RestoreClientAction;
use App\Actions\Clients\UpdateClientAction;
use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use App\Enums\Role;
use App\Http\Requests\ClientIndexRequest;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Clients\ClientPresenter;
use App\Support\Clients\ClientTabPresenter;
use App\Support\Options\LanguageOptions;
use App\Support\Options\TimezoneOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    /**
     * Display a listing of the organization's clients.
     */
    public function index(ClientIndexRequest $request, ListClientsAction $listClients): Response
    {
        $user = $request->user();
        // Step I-1.5: archived (soft-deleted) clients live behind the "Archived" filter.
        // Step I-2.5: paginated, searched, filtered and sorted in SQL.
        $filters = $request->filters();
        $canViewBilling = $user->can('viewBilling', new Client(['org_id' => $user->org_id]));

        return Inertia::render('clients/index', [
            'clients' => $listClients($user, $filters, $canViewBilling)
                ->through(fn (Client $client) => ClientPresenter::row($client, $canViewBilling)),
            'filters' => $filters,
            'filterOptions' => [
                'statuses' => array_map(fn (ClientStatus $status) => ['value' => $status->value, 'label' => $status->label()], ClientStatus::cases()),
                'owners' => $this->ownerOptions($request),
                'sorts' => ClientIndexRequest::SORTS,
                'perPage' => ClientIndexRequest::PER_PAGE,
            ],
            'healthCounts' => $listClients->healthCounts($user),
            'archivedCount' => Client::onlyTrashed()->where('org_id', $user->org_id)->count(),
            'can' => [
                'create' => $user->can('create', Client::class),
                'manageArchive' => $user->role === Role::Owner,
            ],
        ]);
    }

    /**
     * Show the form for creating a new client.
     */
    public function create(Request $request): Response
    {
        $this->authorize('create', Client::class);

        return Inertia::render('clients/create', [
            'statuses' => array_map(fn (ClientStatus $status) => ['value' => $status->value, 'label' => $status->label()], ClientStatus::cases()),
            'billingCycles' => array_map(fn (BillingCycle $cycle) => ['value' => $cycle->value, 'label' => $cycle->label()], BillingCycle::cases()),
            'languages' => LanguageOptions::options(),
            'owners' => $this->ownerOptions($request),
        ]);
    }

    /**
     * Store a newly created client.
     */
    public function store(StoreClientRequest $request, CreateClientAction $action): RedirectResponse
    {
        $client = $action($request->user(), $request->validated());

        return to_route('clients.show', $client);
    }

    /**
     * Display the specified client: the Social accounts tab (Step I-2.4 — every other tab is its
     * own route in `ClientTabController`, sharing the header from `BuildClientPageHeaderAction`).
     */
    public function show(Request $request, Client $client, BuildClientPageHeaderAction $header): Response
    {
        $this->authorize('view', $client);

        $user = $request->user();
        $canViewSocialAccounts = $user->can('viewAny', [SocialAccount::class, $client]);

        return Inertia::render('clients/tabs/accounts', [
            ...$header($client, $user, 'accounts'),
            'socialAccounts' => $canViewSocialAccounts
                ? $client->socialAccounts()
                    ->latest()
                    ->get()
                    ->map(fn (SocialAccount $socialAccount) => ClientTabPresenter::socialAccount($socialAccount, $user))
                : [],
            'timezones' => $canViewSocialAccounts ? TimezoneOptions::options() : [],
        ]);
    }

    /**
     * Show the form for editing the specified client.
     */
    public function edit(Request $request, Client $client): Response
    {
        $this->authorize('update', $client);

        return Inertia::render('clients/edit', [
            'client' => ClientPresenter::detail($client, $request->user()->can('viewBilling', $client)),
            'statuses' => array_map(fn (ClientStatus $status) => ['value' => $status->value, 'label' => $status->label()], ClientStatus::cases()),
            'billingCycles' => array_map(fn (BillingCycle $cycle) => ['value' => $cycle->value, 'label' => $cycle->label()], BillingCycle::cases()),
            'languages' => LanguageOptions::options(),
            'owners' => $this->ownerOptions($request),
        ]);
    }

    /**
     * Update the specified client.
     */
    public function update(UpdateClientRequest $request, Client $client, UpdateClientAction $action): RedirectResponse
    {
        $action($client, $request->validated());

        return to_route('clients.show', $client);
    }

    /**
     * Remove the specified client.
     */
    public function destroy(Request $request, Client $client, ArchiveClientAction $archive): RedirectResponse
    {
        $this->authorize('delete', $client);

        $archive($client, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name archived. Restore it any time from Archived.', ['name' => $client->name])]);

        return to_route('clients.index');
    }

    /**
     * Step I-1.5 — restore an archived client.
     */
    public function restore(Request $request, Client $client, RestoreClientAction $restore): RedirectResponse
    {
        $this->authorize('restore', $client);

        $restore($client, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is back.', ['name' => $client->name])]);

        return to_route('clients.show', $client);
    }

    /**
     * Step I-1.5 — permanently purge an archived client (blocked while it has invoices).
     */
    public function purge(Request $request, Client $client, PurgeClientAction $purge): RedirectResponse
    {
        $this->authorize('forceDelete', $client);

        $name = $client->name;
        $purge($client, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was permanently deleted.', ['name' => $name])]);

        return to_route('clients.index', ['archived' => 1]);
    }

    /**
     * List the organization's users that can be assigned as a client owner.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function ownerOptions(Request $request): array
    {
        return User::query()
            ->where('org_id', $request->user()->org_id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['value' => (string) $user->id, 'label' => $user->name])
            ->all();
    }
}
