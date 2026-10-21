import type { HealthReason } from '@/components/clients/health-reasons';
import type { BestTime } from '@/components/post-editor/types';
import type { CurrencyTotal } from '@/lib/currency';
import type { RangeKey } from './format';

/** Mirrors `App\Support\Analytics\OrganizationAnalyticsFilters::METRICS`. */
export type SectionKey =
    | 'clients'
    | 'posts'
    | 'approvals'
    | 'team'
    | 'revenue'
    | 'ai'
    | 'engagement';

export const sectionKeys: SectionKey[] = [
    'clients',
    'posts',
    'approvals',
    'team',
    'revenue',
    'ai',
    'engagement',
];

export const sectionLabels: Record<SectionKey, string> = {
    clients: 'Clients',
    posts: 'Publishing',
    approvals: 'Approvals',
    team: 'Team workload',
    revenue: 'Billing',
    ai: 'AI usage',
    engagement: 'Engagement',
};

/** `OrganizationAnalyticsFilters::toArray()`. */
export type OrgFilters = {
    range: RangeKey;
    from: string;
    to: string;
    granularity: 'day' | 'month';
    timezone: string;
    client_ids: number[];
    campaign_ids: number[];
    platforms: string[];
    metrics: SectionKey[];
};

export type OrgFilterState = Omit<OrgFilters, 'granularity' | 'timezone'>;

export type OrgSeriesBucket = {
    key: string;
    label: string;
    created: number;
    published: number;
    failed: number;
    sent_for_approval: number;
    approved: number;
    /** Only present when the viewer can see revenue. */
    invoiced?: number;
};

export type HealthTone = 'green' | 'amber' | 'red';

export type CampaignRow = {
    id: number;
    name: string;
    client_id: number;
    client_name: string;
    status: string;
    published: number;
    scheduled: number;
    waiting_on_client: number;
};

export type TopClientRow = {
    id: number;
    name: string;
    status: string;
    health: HealthTone;
    health_reason: string;
    published: number;
    scheduled: number;
    waiting_on_client: number;
    invoiced?: number;
};

export type Turnaround = {
    median_hours: number | null;
    p90_hours: number | null;
};

export type TeamMember = {
    id: number;
    name: string;
    role: string;
    role_label: string;
    posts_created: number;
    transitions: number;
    sent_to_client: number;
    scheduled: number;
};

/** The `GetOrganizationAnalyticsSummaryAction` result. Sections the viewer can't see are null. */
export type OrgSummary = {
    scope: 'organization' | 'client';
    organization: { name: string; timezone: string; default_currency: string };
    client: {
        id: number;
        name: string;
        status: string;
        health: HealthTone;
        reason: string;
        /** Step M-3.4: weighted reasons, filtered for the viewer. */
        reasons?: HealthReason[];
    } | null;
    filters: OrgFilters;
    metrics: SectionKey[];
    clients: {
        total: number;
        active: number;
        paused: number;
        offboarding: number;
        new: number;
        health: Record<HealthTone, number>;
        attention: {
            id: number;
            name: string;
            health: HealthTone;
            reason: string;
        }[];
    } | null;
    posts: {
        created: number;
        published: number;
        failed: number;
        success_rate: number | null;
        scheduled: number;
        pipeline: { status: string; label: string; count: number }[];
        by_platform: { platform: string; label: string; published: number }[];
        top_campaigns: CampaignRow[];
    } | null;
    approvals: {
        sent_to_client: number;
        approved: number;
        changes_requested: number;
        first_time_approval_rate: number | null;
        client_turnaround: Turnaround & { decisions: number };
        internal_turnaround: Turnaround & { reviews: number };
        waiting_now: number;
        oldest_waiting: {
            post_id: number;
            client_id: number;
            client_name: string;
            caption: string | null;
            since: string;
            hours_waiting: number;
        } | null;
    } | null;
    team: {
        members: TeamMember[];
        client_decisions: number;
        totals: {
            posts_created: number;
            transitions: number;
            sent_to_client: number;
            scheduled: number;
        };
    } | null;
    revenue: {
        invoiced: CurrencyTotal[];
        collected: CurrencyTotal[];
        outstanding: CurrencyTotal[];
        monthly_run_rate: CurrencyTotal[];
        invoices_issued: number;
        invoices_paid: number;
        overdue: CurrencyTotal[];
        overdue_count: number;
        average_days_to_pay: number | null;
        retainers: { monthly: CurrencyTotal[]; clients: number };
        /** Campaigns enhancement C-8.3: billed lines tagged with a campaign, per currency. */
        by_campaign: {
            campaign_id: number;
            name: string;
            code: string | null;
            colour: string | null;
            currency: string;
            total: number;
        }[];
    } | null;
    ai: {
        generated: number;
        failed: number;
        by_method: {
            method: string;
            label: string;
            succeeded: number;
            failed: number;
        }[];
        images_this_month: {
            used: number;
            quota: number | null;
            remaining: number | null;
        };
        cost_estimate: number | null;
    } | null;
    engagement: EngagementSection | null;
    primary_currency: string;
    series: OrgSeriesBucket[];
    top_clients: TopClientRow[] | null;
    top_campaigns: CampaignRow[] | null;
};

export type EngagementTotals = {
    impressions: number | null;
    reach: number | null;
    views: number | null;
    likes: number | null;
    comments: number | null;
    shares: number | null;
    saves: number | null;
    clicks: number | null;
    engagement: number | null;
};

export type EngagementTopPost = {
    post_id: number;
    client_id: number | null;
    client_name: string | null;
    caption: string | null;
    thumbnail_url: string | null;
    published_at: string;
    platforms: string[];
    post_url: string | null;
    engagement: number | null;
    impressions: number | null;
    reach: number | null;
    views: number | null;
    likes: number | null;
    comments: number | null;
    shares: number | null;
    saves: number | null;
    engagement_rate: number | null;
};

/** Step M-3.1 — `EngagementMetrics::summary()`. Nulls mean "not reported", never zero. */
export type EngagementSection = {
    published_channels: number;
    measured_channels: number;
    totals: EngagementTotals;
    engagement_rate: number | null;
    by_platform: {
        platform: string;
        label: string;
        available: boolean;
        note: string | null;
        published: number;
        measured: number;
        engagement: number | null;
        impressions: number | null;
        reach: number | null;
        engagement_rate: number | null;
    }[];
    followers: {
        current: number | null;
        growth: number | null;
        accounts: {
            social_account_id: number;
            client_id: number;
            platform: string;
            platform_label: string;
            handle: string;
            followers: number;
            growth: number | null;
        }[];
    };
    series: {
        key: string;
        label: string;
        engagement: number;
        impressions: number;
        followers: number | null;
    }[];
    top_posts: EngagementTopPost[];
    last_synced_at: string | null;
    /** Step M-3.2: per account on the client drill-down; null on org-wide views. */
    best_times: AccountBestTime[] | null;
};

export type AccountBestTime = BestTime & {
    social_account_id: number;
    platform: string;
    platform_label: string;
    handle: string;
    timezone: string;
};

export type OrgFilterOptions = {
    clients: { value: number; label: string; archived: boolean }[];
    campaigns: {
        value: number;
        label: string;
        client_id: number;
        client: string;
    }[];
    platforms: { value: string; label: string }[];
};

export type AnalyticsAbilities = {
    viewRevenue: boolean;
    viewAiCost: boolean;
    saveReport: boolean;
    export: boolean;
};

export function stateFromFilters(filters: OrgFilters): OrgFilterState {
    return {
        range: filters.range,
        from: filters.from,
        to: filters.to,
        client_ids: filters.client_ids,
        campaign_ids: filters.campaign_ids,
        platforms: filters.platforms,
        metrics: filters.metrics,
    };
}

/** The query string `OrganizationAnalyticsFilterRequest` reads (mirrors `toQuery()`). */
export function orgFilterQuery(
    state: Partial<OrgFilterState>,
): Record<string, string | number | (string | number)[]> {
    return {
        ...(state.range ? { range: state.range } : {}),
        ...(state.range === 'custom' && state.from && state.to
            ? { from: state.from, to: state.to }
            : {}),
        ...(state.client_ids?.length ? { client_ids: state.client_ids } : {}),
        ...(state.campaign_ids?.length
            ? { campaign_ids: state.campaign_ids }
            : {}),
        ...(state.platforms?.length ? { platforms: state.platforms } : {}),
        ...(state.metrics?.length ? { metrics: state.metrics } : {}),
    };
}
