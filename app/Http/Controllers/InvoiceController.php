<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\CreateInvoiceAction;
use App\Actions\Invoices\DeleteInvoiceAction;
use App\Actions\Invoices\MarkInvoicePaidAction;
use App\Actions\Invoices\SendInvoiceAction;
use App\Actions\Invoices\UpdateInvoiceAction;
use App\Actions\Invoices\UploadInvoicePdfAction;
use App\Enums\InvoiceStatus;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Http\Requests\UploadInvoicePdfRequest;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
     * Display the specified invoice.
     */
    public function show(Request $request, Invoice $invoice): Response
    {
        $this->authorize('view', $invoice);

        $user = $request->user();

        return Inertia::render('invoices/show', [
            'invoice' => $this->transform($invoice),
            'client' => [
                'id' => $invoice->client_id,
                'name' => $invoice->client->name,
            ],
            'can' => [
                'update' => $user->can('update', $invoice),
                'delete' => $user->can('delete', $invoice),
                'send' => $invoice->status === InvoiceStatus::Draft && $user->can('send', $invoice),
                'mark_paid' => $invoice->status === InvoiceStatus::Sent && $user->can('markPaid', $invoice),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified invoice.
     */
    public function edit(Request $request, Invoice $invoice): Response
    {
        $this->authorize('update', $invoice);

        return Inertia::render('invoices/edit', [
            'invoice' => $this->transform($invoice),
            'client' => [
                'id' => $invoice->client_id,
                'name' => $invoice->client->name,
            ],
        ]);
    }

    /**
     * Update the specified invoice's editable details.
     */
    public function update(UpdateInvoiceRequest $request, Invoice $invoice, UpdateInvoiceAction $action): RedirectResponse
    {
        $action($invoice, $request->validated());

        return to_route('invoices.show', $invoice);
    }

    /**
     * Delete the specified invoice.
     */
    public function destroy(Invoice $invoice, DeleteInvoiceAction $action): RedirectResponse
    {
        $this->authorize('delete', $invoice);

        $clientId = $invoice->client_id;

        $action($invoice);

        return to_route('clients.show', $clientId);
    }

    /**
     * Upload (or overwrite) the PDF for the given invoice.
     */
    public function uploadPdf(UploadInvoicePdfRequest $request, Invoice $invoice, UploadInvoicePdfAction $action): RedirectResponse
    {
        $action($invoice, $request->file('pdf'));

        return to_route('invoices.show', $invoice);
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

    /**
     * Transform an invoice model into an array for the show/edit pages.
     *
     * @return array<string, mixed>
     */
    private function transform(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status->value,
            'status_label' => $invoice->status->label(),
            'amount' => (string) $invoice->amount,
            'currency' => $invoice->currency,
            'issue_date' => $invoice->issue_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'description' => $invoice->description,
            'sent_at' => $invoice->sent_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'pdf_url' => $invoice->pdf_url,
            'pdf_original_filename' => $invoice->pdf_original_filename,
        ];
    }
}
