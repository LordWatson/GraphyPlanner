<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\TaxRate;
use App\Support\Invoices\InvoiceTotals;
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

            // Step M-4.1: a fixed-amount invoice (an `amount` typed up front, no line items) can
            // carry an invoice-level tax rate; default it to the client's. The typed amount is the
            // net, and the grand total (`amount`) is net + tax.
            $net = $data['amount'] ?? null;
            $taxRateId = array_key_exists('tax_rate_id', $data)
                ? $data['tax_rate_id']
                : ($net !== null ? $client->default_tax_rate_id : null);
            $taxRate = $taxRateId !== null
                ? TaxRate::query()->withoutOrgScope()->where('org_id', $client->org_id)->find((int) $taxRateId)
                : null;

            $totals = $net !== null
                ? InvoiceTotals::fromLines([['net' => (float) $net, 'rate' => $taxRate?->rate, 'name' => $taxRate?->name]])
                : null;

            // An itemised invoice has no amount up front — it's computed on finalize.
            $totalColumns = $totals?->toInvoiceColumns() ?? ['amount' => 0, 'subtotal' => null, 'tax_total' => 0, 'tax_breakdown' => null];

            $invoice = Invoice::create([
                ...$data,
                ...$totalColumns,
                'tax_rate_id' => $taxRate?->id,
                // No explicit due date → apply the organization's default payment terms.
                'due_date' => $data['due_date']
                    ?? $issueDate->copy()->addDays($client->organization->invoice_payment_terms_days),
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'invoice_number' => $this->nextInvoiceNumber($client),
                'status' => InvoiceStatus::Draft,
                // The currency chosen for this invoice, falling back to the client's billing
                // currency (its own, or the organization default). Every figure on the invoice is
                // in this currency — no conversions are performed.
                'currency' => $data['currency'] ?? $client->billingCurrency(),
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
