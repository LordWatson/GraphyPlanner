<?php

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 6.6 — the client portal's read-only invoices list/detail: reuses `InvoicePolicy`'s
 * client-scoping intent, but access is enforced by `EnsureClientPortalAccess` scoping every query
 * to the logged-in contact's own `client_id` (never a value taken from the request), per §3's "no
 * billing visibility beyond their own invoices" boundary — no create/edit/send/mark-paid actions
 * are exposed here.
 */
class PortalInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $invoices = Invoice::query()
            ->where('client_id', $request->user()->client_id)
            ->latest('issue_date')
            ->get()
            ->map(fn (Invoice $invoice) => $this->transform($invoice))
            ->values();

        return Inertia::render('portal/invoices/index', [
            'invoices' => $invoices,
        ]);
    }

    public function show(Invoice $invoice): Response
    {
        return Inertia::render('portal/invoices/show', [
            'invoice' => $this->transform($invoice),
        ]);
    }

    /**
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
            'is_unpaid' => $invoice->status !== InvoiceStatus::Paid,
        ];
    }
}
