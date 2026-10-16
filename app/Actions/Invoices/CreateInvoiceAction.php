<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateInvoiceAction
{
    /**
     * Create a new draft invoice for the given client.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Client $client, array $data): Invoice
    {
        return DB::transaction(function () use ($client, $data) {
            $issueDate = Carbon::parse($data['issue_date']);

            $invoice = Invoice::create([
                ...$data,
                // Optional up front: an invoice built from line items gets its amount computed
                // when it's finalized.
                'amount' => $data['amount'] ?? 0,
                // No explicit due date → apply the organization's default payment terms.
                'due_date' => $data['due_date']
                    ?? $issueDate->copy()->addDays($client->organization->invoice_payment_terms_days),
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'invoice_number' => $this->nextInvoiceNumber($client),
                'status' => InvoiceStatus::Draft,
                // The currency chosen for this invoice, falling back to the organization's
                // default. Every figure on the invoice (items, retainer, total) is in this
                // currency — no conversions are performed.
                'currency' => $data['currency'] ?? $client->organization->default_currency,
            ]);

            Log::info('Invoice created', [
                'client_id' => $client->id,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'currency' => $invoice->currency,
            ]);

            return $invoice;
        });
    }

    /**
     * Generate the next sequential invoice number for the client's organization.
     *
     * Derived from the highest existing `invoice_number` for the org (not a row count), and the
     * selecting query is locked (`lockForUpdate`) within the surrounding transaction so concurrent
     * invoice creations for the same org can't compute the same "next" number. Using a row count
     * previously caused duplicate numbers (and a unique constraint violation) once any invoice for
     * the org was deleted, or when two orgs' counts happened to coincide under the old globally
     * unique column.
     */
    private function nextInvoiceNumber(Client $client): string
    {
        $lastNumber = Invoice::where('org_id', $client->org_id)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('invoice_number');

        $nextSequence = $lastNumber ? ((int) substr($lastNumber, 4)) + 1 : 1;

        return sprintf('INV-%06d', $nextSequence);
    }
}
