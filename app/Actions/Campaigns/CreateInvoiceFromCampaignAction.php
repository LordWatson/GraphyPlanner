<?php

namespace App\Actions\Campaigns;

use App\Actions\InvoiceItems\AddInvoiceItemAction;
use App\Actions\Invoices\CreateInvoiceAction;
use App\Enums\CampaignBillingType;
use App\Enums\InvoiceStatus;
use App\Models\Campaign;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step C-8.2 — "Invoice this campaign": add the fee still to bill as a line on the client's open
 * draft invoice (one that hasn't been finalized), or a new draft. It goes through the existing
 * invoice Actions, so finalize, send and ledger rules apply unchanged; a finalized invoice is never
 * touched.
 *
 * Billing a campaign that already has a line on a live invoice is refused unless `$confirmed`, so
 * nobody bills the same fee twice by accident.
 */
class CreateInvoiceFromCampaignAction
{
    public function __construct(
        private readonly CreateInvoiceAction $createInvoice,
        private readonly AddInvoiceItemAction $addItem,
    ) {}

    public function __invoke(Campaign $campaign, User $user, bool $confirmed = false): Invoice
    {
        if ($campaign->billing_type !== CampaignBillingType::FixedFee || $campaign->fee_amount === null) {
            throw ValidationException::withMessages(['fee_amount' => 'Set a fixed fee on the campaign first.']);
        }

        $invoice = DB::transaction(function () use ($campaign, $user, $confirmed) {
            $invoiced = (float) $campaign->invoiceItems()
                ->whereHas('invoice', fn ($query) => $query->where('status', '!=', InvoiceStatus::Void))
                ->selectRaw('coalesce(sum(price * quantity), 0) as total')
                ->value('total');

            if ($invoiced > 0 && ! $confirmed) {
                throw ValidationException::withMessages(['confirm' => 'This campaign is already on an invoice. Invoice it again anyway?']);
            }

            $remaining = round((float) $campaign->fee_amount - $invoiced, 2);
            $amount = $remaining > 0 ? $remaining : (float) $campaign->fee_amount;

            $invoice = Invoice::query()
                ->where('client_id', $campaign->client_id)
                ->where('status', InvoiceStatus::Draft)
                ->where('is_finalized', false)
                ->latest('id')
                ->lockForUpdate()
                ->first()
                ?? ($this->createInvoice)($campaign->client, [
                    'issue_date' => now()->toDateString(),
                    'due_date' => null,
                    'description' => $campaign->name,
                ]);

            ($this->addItem)($invoice, [
                'campaign_id' => $campaign->id,
                'description' => trim($campaign->name.($campaign->code ? " ({$campaign->code})" : '')).' — campaign fee',
                'price' => $amount,
                'quantity' => 1,
                'is_billable' => true,
                // Step M-4.1: the fee inherits the client's default tax rate.
                'tax_rate_id' => $campaign->client->default_tax_rate_id,
            ]);

            $campaign->recordActivity('invoice.added', $user, meta: ['invoice_id' => $invoice->id, 'amount' => $amount]);

            return $invoice;
        });

        Log::info('Campaign fee added to invoice', [
            'campaign_id' => $campaign->id,
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
        ]);

        return $invoice;
    }
}
