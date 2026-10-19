<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The organization-wide invoice ledger: one filtered query shared by the invoices index page and
 * its CSV export (so both always agree on what "overdue", "outstanding", etc. mean), plus the
 * summary figures shown above the ledger.
 */
class InvoiceLedgerService
{
    /**
     * Status filters the ledger understands: every stored status, plus the derived `overdue`
     * (sent + past due) and `outstanding` (sent, whether overdue or not).
     */
    public const STATUS_FILTERS = ['draft', 'sent', 'overdue', 'outstanding', 'paid', 'void'];

    /**
     * @param  array{status?: string|null, client_id?: int|string|null, search?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Builder<Invoice>
     */
    public function query(int $orgId, array $filters = []): Builder
    {
        $status = $filters['status'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));

        return Invoice::query()
            ->where('invoices.org_id', $orgId)
            ->with('client:id,name,org_id')
            ->when($status === 'overdue', fn (Builder $query) => $query->overdue())
            ->when($status === 'outstanding', fn (Builder $query) => $query->where('status', InvoiceStatus::Sent))
            ->when(
                in_array($status, ['draft', 'sent', 'paid', 'void'], true),
                fn (Builder $query) => $query->where('status', $status),
            )
            ->when(filled($filters['client_id'] ?? null), fn (Builder $query) => $query->where('client_id', (int) $filters['client_id']))
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.mb_strtolower($search).'%';

                $query->where(function (Builder $query) use ($like) {
                    $query->whereRaw('LOWER(invoice_number) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(description) LIKE ?', [$like])
                        ->orWhereHas('client', fn (Builder $client) => $client->whereRaw('LOWER(name) LIKE ?', [$like]));
                });
            })
            ->when(filled($filters['from'] ?? null), fn (Builder $query) => $query->whereDate('issue_date', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn (Builder $query) => $query->whereDate('issue_date', '<=', $filters['to']))
            ->orderByDesc('issue_date')
            ->orderByDesc('id');
    }

    /**
     * Headline figures for the ledger page (always org-wide, independent of the active filters).
     *
     * Money totals are split per currency (each invoice is billed in its own currency and no
     * conversion is performed, so amounts in different currencies are never added together).
     * The organization's default currency is listed first.
     *
     * @return array{outstanding_totals: list<array{currency: string, total: float}>, outstanding_count: int, overdue_totals: list<array{currency: string, total: float}>, overdue_count: int, paid_this_month_totals: list<array{currency: string, total: float}>, paid_this_month_count: int, draft_count: int, average_days_to_pay: int|null}
     */
    public function summary(int $orgId): array
    {
        $base = fn () => Invoice::query()->where('org_id', $orgId);

        $outstanding = $base()->where('status', InvoiceStatus::Sent);
        $overdue = $base()->overdue();
        $paidThisMonth = $base()
            ->where('status', InvoiceStatus::Paid)
            ->where('paid_at', '>=', Carbon::now()->startOfMonth());

        // Average time from sending to payment over the last year — computed in PHP so it
        // behaves identically on MySQL (production) and SQLite (tests).
        $paidDurations = $base()
            ->where('status', InvoiceStatus::Paid)
            ->whereNotNull('sent_at')
            ->where('paid_at', '>=', Carbon::now()->subYear())
            ->get(['sent_at', 'paid_at'])
            ->map(fn (Invoice $invoice) => max(0, (int) $invoice->sent_at?->diffInDays($invoice->paid_at)));

        $defaultCurrency = Organization::query()->whereKey($orgId)->value('default_currency');

        return [
            'outstanding_totals' => $this->totalsByCurrency(clone $outstanding, $defaultCurrency),
            'outstanding_count' => $outstanding->count(),
            'overdue_totals' => $this->totalsByCurrency(clone $overdue, $defaultCurrency),
            'overdue_count' => $overdue->count(),
            'paid_this_month_totals' => $this->totalsByCurrency(clone $paidThisMonth, $defaultCurrency),
            'paid_this_month_count' => $paidThisMonth->count(),
            'draft_count' => $base()->where('status', InvoiceStatus::Draft)->count(),
            'average_days_to_pay' => $paidDurations->isEmpty() ? null : (int) round($paidDurations->avg()),
        ];
    }

    /**
     * Sum `amount` per currency, the organization's default currency first, then alphabetical.
     *
     * @param  Builder<Invoice>  $query
     * @return list<array{currency: string, total: float}>
     */
    private function totalsByCurrency(Builder $query, ?string $defaultCurrency): array
    {
        return array_values($query
            ->toBase()
            ->selectRaw('currency, SUM(amount) as total')
            ->groupBy('currency')
            ->get()
            ->map(fn (object $row) => ['currency' => (string) $row->currency, 'total' => (float) $row->total])
            ->sortBy(fn (array $row) => [$row['currency'] === $defaultCurrency ? 0 : 1, $row['currency']])
            ->all());
    }

    /**
     * Stream the filtered ledger as CSV rows (header first), for accountants/spreadsheets.
     *
     * @param  array{status?: string|null, client_id?: int|string|null, search?: string|null, from?: string|null, to?: string|null}  $filters
     * @return iterable<int, array<int, string>>
     */
    public function csvRows(int $orgId, array $filters = []): iterable
    {
        yield ['Invoice number', 'Client', 'Status', 'Issue date', 'Due date', 'Amount', 'Currency', 'Sent at', 'Paid at', 'Payment reference', 'Notes'];

        foreach ($this->query($orgId, $filters)->lazy() as $invoice) {
            yield [
                $invoice->invoice_number,
                $invoice->client->name,
                $invoice->displayStatusLabel(),
                $invoice->issue_date->toDateString(),
                $invoice->due_date?->toDateString() ?? '',
                number_format((float) $invoice->amount, 2, '.', ''),
                $invoice->currency,
                $invoice->sent_at?->toDateString() ?? '',
                $invoice->paid_at?->toDateString() ?? '',
                $invoice->payment_reference ?? '',
                $invoice->description ?? '',
            ];
        }
    }
}
