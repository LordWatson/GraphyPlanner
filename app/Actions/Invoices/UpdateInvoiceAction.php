<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateInvoiceAction
{
    /**
     * Update an invoice's editable details (amount, dates, description). Status, invoice_number,
     * org_id, client_id, and currency (always the organization's default_currency — no
     * per-invoice override) are never touched here. Once finalized, `amount` is locked and can
     * no longer be changed through this action.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Invoice $invoice, array $data): Invoice
    {
        if ($invoice->is_finalized && array_key_exists('amount', $data) && (string) $data['amount'] !== (string) $invoice->amount) {
            throw ValidationException::withMessages([
                'amount' => 'The amount can no longer be changed once the invoice is finalized.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $data) {
            unset($data['currency']);

            $invoice->update($data);

            Log::info('Invoice updated', [
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
            ]);

            return $invoice;
        });
    }
}
