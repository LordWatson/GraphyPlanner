/**
 * Formats an amount in the given ISO 4217 currency. For an invoice, pass the invoice's own
 * `currency`; for org-level figures (e.g. a client's retainer), the organization's default
 * currency shared via the `currency` Inertia prop (see `HandleInertiaRequests::share()`). No
 * currency conversion is performed; this only controls how amounts are displayed.
 */
export function formatCurrency(
    amount: number | string,
    currency: string,
): string {
    const numericAmount = typeof amount === 'string' ? Number(amount) : amount;

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
    }).format(numericAmount);
}

/**
 * Formats an amount using compact notation (e.g. "£5K", "£1.2M") so large figures don't overflow
 * tight UI spaces like stat cards. Pair this with `formatCurrency()` shown in a tooltip so the
 * exact amount is still available on hover.
 */
export function formatCurrencyCompact(
    amount: number | string,
    currency: string,
): string {
    const numericAmount = typeof amount === 'string' ? Number(amount) : amount;

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
        notation: 'compact',
        // Both bounds explicit: a currency's default minimum (2) above this maximum is resolved
        // differently by Node's ICU (SSR) and browsers ("$0.0" vs "$0"), breaking hydration.
        minimumFractionDigits: 0,
        maximumFractionDigits: 1,
    }).format(numericAmount);
}

/** A money total in one currency (see `InvoiceLedgerService::summary()`). */
export type CurrencyTotal = { currency: string; total: number };

/**
 * Sums amounts per currency — amounts in different currencies are never added together. The
 * `primary` currency (usually the organization's default) is listed first, then alphabetical.
 */
export function totalsByCurrency(
    entries: { amount: number | string; currency: string }[],
    primary?: string,
): CurrencyTotal[] {
    const totals = new Map<string, number>();

    for (const entry of entries) {
        totals.set(
            entry.currency,
            (totals.get(entry.currency) ?? 0) + Number(entry.amount),
        );
    }

    return [...totals.entries()]
        .map(([currency, total]) => ({ currency, total }))
        .sort((a, b) =>
            a.currency === primary
                ? -1
                : b.currency === primary
                  ? 1
                  : a.currency.localeCompare(b.currency),
        );
}

/**
 * Formats per-currency totals as one string, e.g. "£1,200.00 + €300.00". An empty list renders
 * as zero in the `fallback` currency.
 */
export function formatCurrencyTotals(
    totals: CurrencyTotal[],
    fallback: string,
    { compact = false }: { compact?: boolean } = {},
): string {
    const format = compact ? formatCurrencyCompact : formatCurrency;

    if (totals.length === 0) {
        return format(0, fallback);
    }

    return totals
        .map(({ total, currency }) => format(total, currency))
        .join(' + ');
}
