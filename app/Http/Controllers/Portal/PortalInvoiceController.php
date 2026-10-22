<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Invoices\ReportInvoicePaymentAction;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\ReportInvoicePaymentRequest;
use App\Models\Invoice;
use App\Support\Invoices\InvoicePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Step 6.6 — the client portal's read-only invoices list/detail: reuses `InvoicePolicy`'s
 * client-scoping intent, but access is enforced by `EnsureClientPortalAccess` scoping every query
 * to the logged-in contact's own `client_id` (never a value taken from the request), per §3's "no
 * billing visibility beyond their own invoices" boundary — no create/edit/send/mark-paid actions
 * are exposed here. Drafts are the studio's work in progress and are never shown to the client.
 */
class PortalInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $invoices = Invoice::query()
            ->where('client_id', $request->user()->client_id)
            ->where('status', '!=', InvoiceStatus::Draft)
            ->with('items')
            ->latest('issue_date')
            ->latest('id')
            ->get()
            ->map(fn (Invoice $invoice) => InvoicePresenter::portal($invoice))
            ->values();

        return Inertia::render('portal/invoices/index', [
            'invoices' => $invoices,
        ]);
    }

    /**
     * Step I-1.6 — the invoice PDF for the portal contact's own client (the `portal` middleware
     * has already checked the invoice belongs to them). Drafts are never shown.
     */
    public function pdf(Invoice $invoice): StreamedResponse
    {
        abort_if($invoice->status === InvoiceStatus::Draft, 404);

        return $invoice->pdfResponse();
    }

    /**
     * Step M-4.2 — the client reports they've paid this invoice. Scoped to the logged-in contact's
     * own client (never a value from the request); the Action enforces that only a `sent` invoice
     * can be reported.
     */
    public function reportPayment(ReportInvoicePaymentRequest $request, Invoice $invoice, ReportInvoicePaymentAction $action): RedirectResponse
    {
        abort_unless($invoice->client_id === $request->user()->client_id, 404);
        // Drafts are never shown to the client, so there's nothing here to report against.
        abort_if($invoice->status === InvoiceStatus::Draft, 404);

        $action($invoice, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Thanks — we’ll confirm your payment shortly.']);

        return back();
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        abort_if($invoice->status === InvoiceStatus::Draft, 404);

        return Inertia::render('portal/invoices/show', [
            'invoice' => InvoicePresenter::portal($invoice),
            'studio' => [
                'name' => $invoice->organization->name,
                'payment_instructions' => $invoice->status === InvoiceStatus::Sent
                    ? $invoice->organization->payment_instructions
                    : null,
            ],
        ]);
    }
}
