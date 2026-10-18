<?php

namespace App\Services\Analytics;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Support\Analytics\AnalyticsRange;
use App\Support\Analytics\AnalyticsScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Step 17.1 — billing figures from `invoices`, shared by the platform console and organization
 * analytics. "Billed" means sent or paid (draft and void are never counted). Money is never summed
 * across currencies: every figure is a per-currency list, largest first.
 *
 * `issue_date` is a calendar date, so ranges compare it against the range's *local* dates;
 * `paid_at` is an instant, so it's compared in UTC.
 */
class RevenueMetrics
{
    /**
     * Sent + paid invoices for the scope (any date).
     *
     * @return Builder<Invoice>
     */
    public function billed(AnalyticsScope $scope): Builder
    {
        return $scope->applyToClientOwned(Invoice::query())
            ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Paid]);
    }

    /**
     * Billed invoices issued inside the range.
     *
     * @return Builder<Invoice>
     */
    public function issued(AnalyticsScope $scope, AnalyticsRange $range): Builder
    {
        return $this->billed($scope)->whereBetween('issue_date', $range->dateBounds());
    }

    /**
     * Invoices paid inside the range.
     *
     * @return Builder<Invoice>
     */
    public function paid(AnalyticsScope $scope, AnalyticsRange $range): Builder
    {
        return $scope->applyToClientOwned(Invoice::query())
            ->where('status', InvoiceStatus::Paid)
            ->whereBetween('paid_at', $range->utcBounds());
    }

    /**
     * Sent and not yet paid, right now (not range-bound).
     *
     * @return Builder<Invoice>
     */
    public function outstanding(AnalyticsScope $scope): Builder
    {
        return $scope->applyToClientOwned(Invoice::query())->where('status', InvoiceStatus::Sent);
    }

    /**
     * An MRR-style window: what was billed in the 30 days up to the end of the range.
     *
     * @return Builder<Invoice>
     */
    public function runRate(AnalyticsScope $scope, AnalyticsRange $range): Builder
    {
        return $this->billed($scope)->whereBetween('issue_date', [
            $range->to->subDays(29)->toDateString(),
            $range->to->toDateString(),
        ]);
    }

    /**
     * @return array{invoiced: list<array{currency: string, total: float}>, collected: list<array{currency: string, total: float}>, outstanding: list<array{currency: string, total: float}>, monthly_run_rate: list<array{currency: string, total: float}>, invoices_issued: int, invoices_paid: int}
     */
    public function summary(AnalyticsScope $scope, AnalyticsRange $range): array
    {
        $issued = $this->issued($scope, $range);
        $paid = $this->paid($scope, $range);

        return [
            'invoiced' => $this->totalsByCurrency(clone $issued),
            'collected' => $this->totalsByCurrency(clone $paid),
            'outstanding' => $this->totalsByCurrency($this->outstanding($scope)),
            'monthly_run_rate' => $this->totalsByCurrency($this->runRate($scope, $range)),
            'invoices_issued' => $issued->count(),
            'invoices_paid' => $paid->count(),
        ];
    }

    /**
     * Sum `amount` per currency, largest first (ties alphabetical).
     *
     * @param  Builder<Invoice>  $query
     * @return list<array{currency: string, total: float}>
     */
    public function totalsByCurrency(Builder $query): array
    {
        return array_values($query
            ->toBase()
            ->selectRaw('currency, SUM(amount) as total')
            ->groupBy('currency')
            ->get()
            ->map(fn (object $row) => ['currency' => (string) $row->currency, 'total' => round((float) $row->total, 2)])
            ->sortBy([['total', 'desc'], ['currency', 'asc']])
            ->values()
            ->all());
    }

    /**
     * The currency the money series is drawn in. With a preferred currency (an org's default),
     * that one wins whenever anything was invoiced in it in the range; otherwise the currency with
     * the most invoiced; otherwise the preferred currency (or USD).
     */
    public function primaryCurrency(AnalyticsScope $scope, AnalyticsRange $range, ?string $preferred = null): string
    {
        $totals = $this->issued($scope, $range)
            ->toBase()
            ->selectRaw('currency, SUM(amount) as total')
            ->groupBy('currency')
            ->orderByDesc('total')
            ->orderBy('currency')
            ->pluck('total', 'currency');

        if ($preferred !== null && $totals->has($preferred)) {
            return $preferred;
        }

        $top = $totals->keys()->first();

        return is_string($top) ? $top : ($preferred ?? 'USD');
    }

    /**
     * Invoiced per series bucket, in one currency.
     *
     * @return list<array{0: mixed, 1: float}>
     */
    public function invoicedPairs(AnalyticsScope $scope, AnalyticsRange $range, string $currency): array
    {
        return array_values($this->issued($scope, $range)
            ->where('currency', $currency)
            ->get(['amount', 'issue_date'])
            ->map(fn (Invoice $invoice) => [$invoice->issue_date, (float) $invoice->amount])
            ->all());
    }
}
