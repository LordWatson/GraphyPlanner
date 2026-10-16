<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\CreateInvoiceAction;
use App\Actions\Invoices\DeleteInvoiceAction;
use App\Actions\Invoices\DuplicateInvoiceAction;
use App\Actions\Invoices\FinalizeInvoiceAction;
use App\Actions\Invoices\GenerateInvoicePdfAction;
use App\Actions\Invoices\MarkInvoicePaidAction;
use App\Actions\Invoices\SendInvoiceAction;
use App\Actions\Invoices\SendInvoiceReminderAction;
use App\Actions\Invoices\UpdateInvoiceAction;
use App\Actions\Invoices\UploadInvoicePdfAction;
use App\Actions\Invoices\VoidInvoiceAction;
use App\Http\Requests\InvoiceIndexRequest;
use App\Http\Requests\MarkInvoicePaidRequest;
use App\Http\Requests\SendInvoiceReminderRequest;
use App\Http\Requests\SendInvoiceRequest;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Http\Requests\UploadInvoicePdfRequest;
use App\Http\Requests\VoidInvoiceRequest;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\InvoiceLedgerService;
use App\Support\Invoices\InvoicePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    /**
     * The organization-wide invoice ledger: summary figures plus a filtered, paginated list.
     */
    public function index(InvoiceIndexRequest $request, InvoiceLedgerService $ledger): Response
    {
        $orgId = $request->user()->org_id;
        $filters = $request->filters();

        $invoices = $ledger->query($orgId, $filters)
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Invoice $invoice) => InvoicePresenter::row($invoice));

        return Inertia::render('invoices/index', [
            'invoices' => $invoices,
            'summary' => $ledger->summary($orgId),
            'filters' => [
                'status' => $filters['status'] ?? null,
                'client_id' => isset($filters['client_id']) ? (string) $filters['client_id'] : null,
                'search' => $filters['search'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'clients' => Client::query()
                ->where('org_id', $orgId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Client $client) => ['value' => (string) $client->id, 'label' => $client->name]),
        ]);
    }

    /**
     * Download the (filtered) ledger as CSV.
     */
    public function export(InvoiceIndexRequest $request, InvoiceLedgerService $ledger): StreamedResponse
    {
        $orgId = $request->user()->org_id;
        $filters = $request->filters();

        return response()->streamDownload(function () use ($ledger, $orgId, $filters) {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                throw new RuntimeException('Unable to open the output stream for the invoice export.');
            }

            foreach ($ledger->csvRows($orgId, $filters) as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, 'invoices-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Create a new draft invoice for the given client, then open it to add line items.
     */
    public function store(StoreInvoiceRequest $request, Client $client, CreateInvoiceAction $action): RedirectResponse
    {
        $invoice = $action($client, $request->validated());

        $this->toast("Draft {$invoice->invoice_number} created.");

        return to_route('invoices.show', $invoice);
    }

    /**
     * Display the specified invoice.
     */
    public function show(Request $request, Invoice $invoice): Response
    {
        $this->authorize('view', $invoice);

        return Inertia::render('invoices/show', [
            'invoice' => InvoicePresenter::detail($invoice),
            'client' => $this->clientSummary($invoice),
            'can' => InvoicePresenter::abilities($invoice, $request->user()),
        ]);
    }

    /**
     * Show the form for editing the specified invoice.
     */
    public function edit(Request $request, Invoice $invoice): Response
    {
        $this->authorize('update', $invoice);

        return Inertia::render('invoices/edit', [
            'invoice' => InvoicePresenter::detail($invoice),
            'client' => $this->clientSummary($invoice),
            'can' => InvoicePresenter::abilities($invoice, $request->user()),
        ]);
    }

    /**
     * Update the specified invoice's editable details.
     */
    public function update(UpdateInvoiceRequest $request, Invoice $invoice, UpdateInvoiceAction $action): RedirectResponse
    {
        $action($invoice, $request->validated());

        $this->toast('Invoice details saved.');

        return to_route('invoices.show', $invoice);
    }

    /**
     * Delete the specified (draft or void) invoice.
     */
    public function destroy(Invoice $invoice, DeleteInvoiceAction $action): RedirectResponse
    {
        $this->authorize('delete', $invoice);

        $clientId = $invoice->client_id;

        $action($invoice);

        $this->toast("{$invoice->invoice_number} deleted.");

        return to_route('clients.show', $clientId);
    }

    /**
     * Upload (or overwrite) the PDF for the given invoice.
     */
    public function uploadPdf(UploadInvoicePdfRequest $request, Invoice $invoice, UploadInvoicePdfAction $action): RedirectResponse
    {
        $action($invoice, $request->file('pdf'));

        $this->toast('PDF uploaded.');

        return to_route('invoices.show', $invoice);
    }

    /**
     * Generate the branded PDF, replacing any manually uploaded one.
     */
    public function generatePdf(Invoice $invoice, GenerateInvoicePdfAction $action): RedirectResponse
    {
        $this->authorize('update', $invoice);

        $action($invoice);

        $this->toast('Branded PDF generated.');

        return to_route('invoices.show', $invoice);
    }

    /**
     * Send the invoice to the client (email + portal notification).
     */
    public function send(SendInvoiceRequest $request, Invoice $invoice, SendInvoiceAction $action): RedirectResponse
    {
        $result = $action($invoice, $request->validated('email'), $request->validated('message'));

        match (true) {
            $result['emailed'] => $this->toast("{$invoice->invoice_number} emailed to {$result['recipient']}."),
            $result['recipient'] !== null => $this->toast("{$invoice->invoice_number} marked as sent, but the email to {$result['recipient']} failed — try sending a reminder.", 'warning'),
            default => $this->toast("{$invoice->invoice_number} marked as sent. No billing email on file, so nothing was emailed."),
        };

        return back();
    }

    /**
     * Email a payment reminder for a sent invoice.
     */
    public function remind(SendInvoiceReminderRequest $request, Invoice $invoice, SendInvoiceReminderAction $action): RedirectResponse
    {
        $result = $action($invoice, $request->validated('email'), $request->validated('message'));

        $this->toast("Reminder sent to {$result['recipient']}.");

        return back();
    }

    /**
     * Mark the invoice as paid.
     */
    public function markPaid(MarkInvoicePaidRequest $request, Invoice $invoice, MarkInvoicePaidAction $action): RedirectResponse
    {
        $action($invoice, $request->validated());

        $this->toast("{$invoice->invoice_number} marked as paid.");

        return back();
    }

    /**
     * Void a sent invoice.
     */
    public function void(VoidInvoiceRequest $request, Invoice $invoice, VoidInvoiceAction $action): RedirectResponse
    {
        $action($invoice, $request->validated('reason'));

        $this->toast("{$invoice->invoice_number} voided.");

        return back();
    }

    /**
     * Start a new draft from this invoice and open it.
     */
    public function duplicate(Invoice $invoice, DuplicateInvoiceAction $action): RedirectResponse
    {
        $this->authorize('duplicate', $invoice);

        $copy = $action($invoice);

        $this->toast("Draft {$copy->invoice_number} created from {$invoice->invoice_number}.");

        return to_route('invoices.show', $copy);
    }

    /**
     * Finalize the invoice: lock in the computed total (items subtotal + retainer snapshot).
     */
    public function finalize(Invoice $invoice, FinalizeInvoiceAction $action): RedirectResponse
    {
        $this->authorize('finalize', $invoice);

        $action($invoice);

        $this->toast('Invoice finalized — the total is locked in.');

        return to_route('invoices.show', $invoice);
    }

    /**
     * @return array<string, mixed>
     */
    private function clientSummary(Invoice $invoice): array
    {
        $client = $invoice->client;

        return [
            'id' => $client->id,
            'name' => $client->name,
            'legal_name' => $client->legal_name,
            'billing_email' => $client->billing_email,
            'billing_address' => $client->billing_address,
            'recipient_email' => $client->invoiceRecipientEmail(),
            'retainer_amount' => $client->retainer_amount !== null ? (string) $client->retainer_amount : null,
        ];
    }

    private function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }
}
