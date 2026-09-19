<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateInvoiceAction
{
    /**
     * Update an invoice's editable details (amount, currency, dates, description). Status,
     * invoice_number, org_id, and client_id are never touched here.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $invoice->update($data);

            Log::info('Invoice updated', [
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
            ]);

            return $invoice;
        });
    }
}
