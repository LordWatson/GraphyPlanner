<?php

namespace App\Http\Controllers;

use App\Actions\InvoiceItems\AddInvoiceItemAction;
use App\Actions\InvoiceItems\DeleteInvoiceItemAction;
use App\Actions\InvoiceItems\UpdateInvoiceItemAction;
use App\Http\Requests\StoreInvoiceItemRequest;
use App\Http\Requests\UpdateInvoiceItemRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Http\RedirectResponse;

class InvoiceItemController extends Controller
{
    /**
     * Add a new line item to the given (draft) invoice.
     */
    public function store(StoreInvoiceItemRequest $request, Invoice $invoice, AddInvoiceItemAction $action): RedirectResponse
    {
        $action($invoice, $request->validated());

        return to_route('invoices.show', $invoice);
    }

    /**
     * Update the given line item.
     */
    public function update(UpdateInvoiceItemRequest $request, InvoiceItem $invoiceItem, UpdateInvoiceItemAction $action): RedirectResponse
    {
        $action($invoiceItem, $request->validated());

        return to_route('invoices.show', $invoiceItem->invoice_id);
    }

    /**
     * Delete the given line item.
     */
    public function destroy(InvoiceItem $invoiceItem, DeleteInvoiceItemAction $action): RedirectResponse
    {
        $this->authorize('delete', $invoiceItem);

        $invoiceId = $invoiceItem->invoice_id;

        $action($invoiceItem);

        return to_route('invoices.show', $invoiceId);
    }
}
