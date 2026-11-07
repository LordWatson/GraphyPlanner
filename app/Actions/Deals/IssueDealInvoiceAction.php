<?php

namespace App\Actions\Deals;

use App\Actions\Invoices\CreateInvoiceAction;
use App\Actions\Invoices\SendInvoiceAction;
use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Models\BrandDeal;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step GTM-1.2 — bill a sponsor for a brand deal. A creator invoicing a sponsor is the same shape
 * as an agency invoicing a client, so this composes the existing pipeline
 * ({@see CreateInvoiceAction} → PDF → {@see SendInvoiceAction} → reminders) rather than modelling a
 * second kind of invoice. The deal's own brand is the `client_id`; the sponsor is the recipient.
 *
 * Three rules it owns:
 *
 *  - **An agreed fee and an address, or nothing.** A fee-less deal is a record, not a bill, and
 *    with no `contact_email` the invoice would fall back to the creator's own billing contact —
 *    i.e. they would email themselves.
 *  - **Once.** `brand_deals.invoice_id` is the lock: a second attempt is refused rather than
 *    raising a duplicate the sponsor has to work out.
 *  - **The deal's status is not touched.** `DealStatus` is deliberately not a state machine (see
 *    `brand-deals.md`) — the owner sets whichever status is true, and billing isn't delivery.
 */
class IssueDealInvoiceAction
{
    public function __construct(
        private CreateInvoiceAction $createInvoice,
        private SendInvoiceAction $sendInvoice,
    ) {}

    public function __invoke(BrandDeal $deal): Invoice
    {
        $brand = $deal->client;

        if ($brand->organization !== null) {
            app(Entitlements::class)->ensure($brand->organization, Feature::BrandDeals);
        }

        if ($deal->invoice_id !== null) {
            throw ValidationException::withMessages([
                'deal' => __('This deal has already been invoiced.'),
            ]);
        }

        if (($deal->fee_minor ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'fee_minor' => __('Add the agreed fee before invoicing this deal.'),
            ]);
        }

        if (blank($deal->contact_email)) {
            throw ValidationException::withMessages([
                'contact_email' => __('Add the sponsor\'s email address before invoicing this deal.'),
            ]);
        }

        $invoice = DB::transaction(function () use ($deal, $brand): Invoice {
            $invoice = ($this->createInvoice)($brand, [
                'amount' => $deal->fee_minor / 100,
                'currency' => $deal->currency ?: $brand->billingCurrency(),
                'issue_date' => now()->toDateString(),
                'description' => __('Paid partnership — :partner', ['partner' => $deal->partner_name]),
            ]);

            // forceFill: `invoice_id` is deliberately not fillable, so no request can set it.
            $deal->forceFill(['invoice_id' => $invoice->id])->save();

            return $invoice;
        });

        // Outside the transaction: the PDF and the email are external work, and a mail failure is
        // reported (and retried with a reminder), never allowed to undo a raised invoice.
        ($this->sendInvoice)($invoice, $deal->contact_email);

        Log::info('Brand deal invoiced', [
            'deal_id' => $deal->id,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'org_id' => $deal->org_id,
            'client_id' => $deal->client_id,
        ]);

        return $invoice->refresh();
    }
}
