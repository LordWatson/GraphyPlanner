<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\CreateInvoiceAction;
use App\Actions\Invoices\MarkInvoicePaidAction;
use App\Actions\Invoices\SendInvoiceAction;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;

class InvoiceController extends Controller
{
    /**
     * Create a new draft invoice for the given client.
     */
    public function store(StoreInvoiceRequest $request, Client $client, CreateInvoiceAction $action): RedirectResponse
    {
        $action($client, $request->validated());

        return to_route('clients.show', $client);
    }

    /**
     * Send the invoice to the client for billing.
     */
    public function send(Invoice $invoice, SendInvoiceAction $action): RedirectResponse
    {
        $this->authorize('send', $invoice);

        $action($invoice);

        return to_route('clients.show', $invoice->client_id);
    }

    /**
     * Mark the invoice as paid.
     */
    public function markPaid(Invoice $invoice, MarkInvoicePaidAction $action): RedirectResponse
    {
        $this->authorize('markPaid', $invoice);

        $action($invoice);

        return to_route('clients.show', $invoice->client_id);
    }
}
