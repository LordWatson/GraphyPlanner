<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 6.3 — minimal scoped entry point for a single invoice inside the client portal, proving
 * out `EnsureClientPortalAccess`'s isolation guarantee. The full read-only invoices list lands in
 * Step 6.6.
 */
class PortalInvoiceController extends Controller
{
    public function show(Invoice $invoice): Response
    {
        return Inertia::render('portal/invoices/show', [
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status->value,
                'status_label' => $invoice->status->label(),
                'amount' => (string) $invoice->amount,
                'currency' => $invoice->currency,
                'due_date' => $invoice->due_date?->toDateString(),
            ],
        ]);
    }
}
