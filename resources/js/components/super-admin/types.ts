import type { RangeKey } from '@/components/analytics/format';
import type { CurrencyTotal } from '@/lib/currency';

// Step 17.4: range presets and labels are shared with the studio's own analytics.
export type { RangeKey } from '@/components/analytics/format';
export { describeRange, rangeLabels } from '@/components/analytics/format';

/** Mirrors `App\Support\SuperAdmin\AnalyticsFilters::METRICS`. */
export type MetricKey =
    | 'organizations'
    | 'users'
    | 'clients'
    | 'posts'
    | 'revenue'
    | 'ai';

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
