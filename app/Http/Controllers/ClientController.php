<?php

namespace App\Http\Controllers;

use App\Actions\Clients\CreateClientAction;
use App\Actions\Clients\UpdateClientAction;
use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    /**
     * Display a listing of the organization's clients.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Client::class);

        $user = $request->user();

        $clients = Client::query()
            ->where('org_id', $user->org_id)
            ->orderBy('name')
            ->get()
            ->map(fn (Client $client) => $this->transform($client, $user->can('viewBilling', $client)));

        return Inertia::render('clients/index', [
            'clients' => $clients,
            'can' => [
                'create' => $user->can('create', Client::class),
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
     * Display the specified client.
     */
    public function show(Request $request, Client $client): Response
    {
        $this->authorize('view', $client);

        $user = $request->user();
        $canViewBilling = $user->can('viewBilling', $client);

        return Inertia::render('clients/show', [
            'client' => $this->transform($client, $canViewBilling),
            'can' => [
                'update' => $user->can('update', $client),
                'delete' => $user->can('delete', $client),
                'createInvoice' => $user->can('create', [Invoice::class, $client]),
            ],
            'invoices' => $canViewBilling
                ? $client->invoices()
                    ->latest('issue_date')
                    ->get()
                    ->map(fn (Invoice $invoice) => $this->transformInvoice($invoice, $user))
                : [],
        ]);
    }

    /**
     * Show the form for editing the specified client.
     */
    public function edit(Request $request, Client $client): Response
    {
        $this->authorize('update', $client);

        return Inertia::render('clients/edit', [
            'client' => $this->transform($client, $request->user()->can('viewBilling', $client)),
            'statuses' => array_map(fn (ClientStatus $status) => ['value' => $status->value, 'label' => $status->label()], ClientStatus::cases()),
            'billingCycles' => array_map(fn (BillingCycle $cycle) => ['value' => $cycle->value, 'label' => $cycle->label()], BillingCycle::cases()),
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
    public function destroy(Client $client): RedirectResponse
    {
        $this->authorize('delete', $client);

        $client->delete();

        return to_route('clients.index');
    }

    /**
     * Transform a client model into an array, hiding billing fields when not permitted.
     *
     * @return array<string, mixed>
     */
    private function transform(Client $client, bool $canViewBilling): array
    {
        $health = $client->health();

        return [
            'id' => $client->id,
            'name' => $client->name,
            'legal_name' => $client->legal_name,
            'website' => $client->website,
            'industry' => $client->industry,
            'countries' => $client->countries,
            'status' => $client->status->value,
            'owner_user_id' => $client->owner_user_id,
            'start_date' => $client->start_date?->toDateString(),
            'retainer_amount' => $canViewBilling ? $client->retainer_amount : null,
            'billing_cycle' => $canViewBilling ? $client->billing_cycle?->value : null,
            'tags' => $client->tags,
            'default_language' => $client->default_language,
            'notes_internal' => $client->notes_internal,
            'approval_email' => $client->approval_email,
            'health' => $health['status']->value,
            'health_reason' => $health['reason'],
        ];
    }

    /**
     * Transform an invoice model into an array for the invoice history list.
     *
     * @return array<string, mixed>
     */
    private function transformInvoice(Invoice $invoice, User $user): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status->value,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'issue_date' => $invoice->issue_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'description' => $invoice->description,
            'sent_at' => $invoice->sent_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'can' => [
                'send' => $user->can('send', $invoice),
                'mark_paid' => $user->can('markPaid', $invoice),
            ],
        ];
    }
}
