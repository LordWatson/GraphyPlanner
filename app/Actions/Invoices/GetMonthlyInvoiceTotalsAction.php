<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Organization;
use Illuminate\Support\Carbon;

class GetMonthlyInvoiceTotalsAction
{
    /**
     * Build the last `$months` months of invoiced vs. paid totals for an organization,
     * for use in the dashboard revenue chart.
     *
     * Only invoices billed in the organization's default currency are counted: amounts in other
     * currencies can't be summed into the same series without a conversion (which we never do).
     *
     * Grouping is done in PHP (rather than a driver-specific SQL date function) so the
     * query behaves the same on Postgres (production) and SQLite (tests).
     *
     * @return array<int, array{month: string, label: string, invoiced: float, paid: float}>
     */
    public function __invoke(int $orgId, int $months = 6): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths($months - 1);

        $defaultCurrency = Organization::query()->whereKey($orgId)->value('default_currency');

        $invoices = Invoice::query()
            ->where('org_id', $orgId)
            ->where('currency', $defaultCurrency)
            ->where('issue_date', '>=', $start)
            ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Paid])
            ->get(['status', 'amount', 'issue_date']);

        $totals = [];

        for ($i = 0; $i < $months; $i++) {
            $cursor = $start->copy()->addMonths($i);
            $key = $cursor->format('Y-m');

            $monthInvoices = $invoices->filter(
                fn (Invoice $invoice) => $invoice->issue_date->format('Y-m') === $key
            );

            $totals[] = [
                'month' => $key,
                'label' => $cursor->format('M Y'),
                'invoiced' => (float) $monthInvoices->sum('amount'),
                'paid' => (float) $monthInvoices
                    ->where('status', InvoiceStatus::Paid)
                    ->sum('amount'),
            ];
        }

        return $totals;
    }
}
