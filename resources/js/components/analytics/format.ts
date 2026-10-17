import type { CurrencyTotal } from '@/lib/currency';
import { formatCurrencyCompact } from '@/lib/currency';
import { formatDateRange } from '@/lib/dates';

/**
 * Shared analytics formatting (Phase 15 platform console + Phase 17 studio analytics), so both
 * surfaces label ranges, counts and durations identically.
 */

/** Mirrors `App\Support\Analytics\AnalyticsRange::PRESETS`. */
export type RangeKey = '7d' | '30d' | '90d' | '12m' | 'ytd' | 'custom';

export const rangeLabels: Record<RangeKey, string> = {
    '7d': '7 days',
    '30d': '30 days',
    '90d': '90 days',
    '12m': '12 months',
    ytd: 'Year to date',
    custom: 'Custom',
};

export const integer = new Intl.NumberFormat('en-GB');

/** Small figures read better zero-padded in the numeral spec row ("04"), large ones grouped. */
export function pad(value: number): string {
    return value < 10 && value >= 0
        ? String(value).padStart(2, '0')
        : integer.format(value);
}

/** "Last 30 days", "Year to date", or the pinned dates of a custom range. */
export function describeRange(filters: {
    range: RangeKey;
    from: string;
    to: string;
}): string {
    if (filters.range === 'custom') {
        return formatDateRange(filters.from, filters.to);
    }

    return filters.range === 'ytd'
        ? rangeLabels.ytd
        : `Last ${rangeLabels[filters.range]}`;
}

/** Turnaround hours as people say them: "6.5 h" under two days, "3.2 days" beyond. */
export function formatHours(hours: number | null): string {
    if (hours === null) {
        return '—';
    }

    if (hours >= 48) {
        return `${(hours / 24).toFixed(1).replace(/\.0$/, '')} days`;
    }

    return `${hours.toFixed(1).replace(/\.0$/, '')} h`;
}

export function formatPercent(value: number | null): string {
    return value === null ? '—' : `${value.toFixed(1).replace(/\.0$/, '')}%`;
}

/** The headline money total in one currency, plus the other currencies for the caption. */
export function primaryTotal(
    totals: CurrencyTotal[],
    currency: string,
): { headline: string; others: CurrencyTotal[] } {
    const primary = totals.find((total) => total.currency === currency);

    return {
        headline: formatCurrencyCompact(primary?.total ?? 0, currency),
        others: totals.filter((total) => total.currency !== currency),
    };
}

/** Label every n-th tick so a daily axis never crowds (≈8 labels). */
export function tickInterval(length: number, daily: boolean): number {
    return daily ? Math.max(0, Math.ceil(length / 8) - 1) : 0;
}
