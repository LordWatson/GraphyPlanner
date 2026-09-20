<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
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
            $invoice = Invoice::create([
                ...$data,
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'invoice_number' => $this->nextInvoiceNumber($client),
                'status' => InvoiceStatus::Draft,
                // Always billed in the organization's default currency (spec: no per-invoice
                // currency override, no conversions).
                'currency' => $client->organization->default_currency,
            ]);

            Log::info('Invoice created', [
                'client_id' => $client->id,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ]);

            return $invoice;
        });
    }

    /**
     * Generate the next sequential invoice number for the client's organization.
     */
    private function nextInvoiceNumber(Client $client): string
    {
        $count = Invoice::where('org_id', $client->org_id)->count();

        return sprintf('INV-%06d', $count + 1);
    }
}
