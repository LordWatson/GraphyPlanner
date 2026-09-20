/**
 * Formats an amount using the organization's default currency (shared via the `currency`
 * Inertia prop — see `HandleInertiaRequests::share()`). No currency conversion is performed;
 * this only controls how amounts are displayed.
 */
export function formatCurrency(amount: number | string, currency: string): string {
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
export function formatCurrencyCompact(amount: number | string, currency: string): string {
    const numericAmount = typeof amount === 'string' ? Number(amount) : amount;

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(numericAmount);
}
