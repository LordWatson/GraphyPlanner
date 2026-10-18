<?php

namespace App\Http\Controllers\Campaigns;

use App\Actions\Campaigns\CreateInvoiceFromCampaignAction;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Step C-8.2 — "Invoice this campaign" (Owner/Strategist; Phase 17's revenue rule). Lands on the
 * draft invoice so the studio can check it before finalizing.
 */
class CampaignInvoiceController extends Controller
{
    public function __invoke(Request $request, Campaign $campaign, CreateInvoiceFromCampaignAction $action): RedirectResponse
    {
        $this->authorize('viewBilling', $campaign);
        $this->authorize('create', [Invoice::class, $campaign->client]);

        $invoice = $action($campaign, $request->user(), $request->boolean('confirm'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Fee added to :number. Check it, then finalize.', ['number' => $invoice->invoice_number])]);

        return to_route('invoices.show', $invoice);
    }
}
