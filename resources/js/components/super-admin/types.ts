import type { CurrencyTotal } from '@/lib/currency';
import { formatDateRange } from '@/lib/dates';

/** Mirrors `App\Support\SuperAdmin\AnalyticsFilters::METRICS`. */
export type MetricKey =
    | 'organizations'
    | 'users'
    | 'clients'
    | 'posts'
    | 'revenue'
    | 'ai';

/** Mirrors `AnalyticsFilters::RANGES`. */
export type RangeKey = '7d' | '30d' | '90d' | '12m' | 'ytd' | 'custom';

export type ResolvedFilters = {
    range: RangeKey;
    from: string;
    to: string;
    org_ids: number[];
    metrics: MetricKey[];
    granularity: 'day' | 'month';
};

export type SeriesBucket = {
    key: string;
    label: string;
    signups: number;
    published: number;
    failed: number;
    invoiced: number;
};

export type TopOrganization = {
    id: number;
    name: string;
    slug: string;
    suspended: boolean;
    published: number;
    clients: number;
    users: number;
    invoiced: number;
};

/** The `GetPlatformAnalyticsSummaryAction` result. Unselected sections are null. */
export type PlatformSummary = {
    filters: ResolvedFilters;
    metrics: MetricKey[];
    organizations: {
        total: number;
        active: number;
        suspended: number;
        new: number;
    } | null;
    users: {
        total: number;
        new: number;
        by_role: { role: string; label: string; count: number }[];
    } | null;
    clients: { total: number; active: number; new: number } | null;
    posts: {
        total: number;
        published_all_time: number;
        published: number;
        published_last_30_days: number;
        failed: number;
        success_rate: number | null;
        scheduled: number;
    } | null;
    revenue: {
        invoiced: CurrencyTotal[];
        collected: CurrencyTotal[];
        outstanding: CurrencyTotal[];
        monthly_run_rate: CurrencyTotal[];
        invoices_issued: number;
        invoices_paid: number;
    } | null;
    ai: { generated: number; failed: number; cost_estimate: number } | null;
    engagement: null;
    primary_currency: string;
    series: SeriesBucket[];
    top_organizations: TopOrganization[];
};

export type Option = { value: number; label: string };

export const metricLabels: Record<MetricKey, string> = {
    organizations: 'Organizations',
    users: 'People',
    clients: 'Clients',
    posts: 'Publishing',
    revenue: 'Billing',
    ai: 'AI usage',
};

export const rangeLabels: Record<RangeKey, string> = {
    '7d': '7 days',
    '30d': '30 days',
    '90d': '90 days',
    '12m': '12 months',
    ytd: 'Year to date',
    custom: 'Custom',
};

/** "Last 30 days", "Year to date", or the pinned dates of a custom range. */
export function describeRange(filters: ResolvedFilters): string {
    if (filters.range === 'custom') {
        return formatDateRange(filters.from, filters.to);
    }

    return filters.range === 'ytd'
        ? rangeLabels.ytd
        : `Last ${rangeLabels[filters.range]}`;
}
