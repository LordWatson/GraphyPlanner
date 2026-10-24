import { Deferred, Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CalendarDays,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    FilePenLine,
    HeartPulse,
    ImageOff,
    LineChart,
    RefreshCw,
    Users,
    WifiOff,
} from 'lucide-react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

import {
    ChapterHero,
    EmptyState,
    HeroStat,
    Page,
    Panel,
    StatStrip,
} from '@/components/page';
import type { LiveCampaign } from '@/components/campaigns/live-campaigns-panel';
import { LiveCampaignsPanel } from '@/components/campaigns/live-campaigns-panel';
import { HomeViewTabs } from '@/components/home-view-tabs';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { calendar, dashboard, needsAttention } from '@/routes';
import { index as analyticsIndex } from '@/routes/analytics';
import { index as clientsIndex, show as showClient } from '@/routes/clients';
import { edit as editPost } from '@/routes/posts';
import type { Auth } from '@/types';

type DashboardSummary = {
    clients: number;
    brands: number;
    scheduledPosts: number;
    scheduledNext24h: number;
    needsAttention: number;
    unapprovedDrafts: number;
    activeClients: number;
};

type InvoiceMonthlyTotal = {
    month: string;
    label: string;
    invoiced: number;
    paid: number;
};

type PublishReliabilityMonth = {
    month: string;
    label: string;
    failed: number;
    published: number;
};

type PublishReliability = {
    monthly: PublishReliabilityMonth[];
    averageAttemptsToSuccess: number | null;
};

type UpcomingPostOccurrence = {
    post_id: number;
    target_id: number;
    client_name: string | null;
    platform: string | null;
    handle: string | null;
    date: string | null;
    time: string | null;
    scheduled_at_utc: string | null;
};

type NeedsAttentionRow = {
    key: string;
    type:
        | 'failed'
        | 'disconnected'
        | 'internal_review'
        | 'waiting'
        | 'missing_media';
    title: string;
    subtitle: string;
    client_id: number | null;
    client_name: string | null;
    post_id: number | null;
};

type ClientHealthRow = {
    id: number;
    name: string;
    post_count: number;
    retainer_amount: number | null;
    billing_cycle_label: string | null;
    status: 'red' | 'amber' | 'green';
    status_label: string;
    reason: string;
};

type UnapprovedDraftRow = {
    post_id: number;
    title: string;
    client_name: string | null;
    status: string;
    status_label: string;
};

type DashboardProps = {
    summary: DashboardSummary;
    invoiceTotals: InvoiceMonthlyTotal[];
    upcomingPosts: UpcomingPostOccurrence[];
    needsAttentionItems: NeedsAttentionRow[];
    clientHealth: ClientHealthRow[];
    unapprovedDraftsList: UnapprovedDraftRow[];
    publishReliability: PublishReliability;
    liveCampaigns?: { items: LiveCampaign[]; total: number };
};

const dayFormatter = new Intl.DateTimeFormat('en-US', { day: '2-digit' });
const weekdayFormatter = new Intl.DateTimeFormat('en-US', {
    weekday: 'short',
});
const todayFormatter = new Intl.DateTimeFormat('en-US', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
});

const attentionMeta = {
    failed: {
        icon: AlertTriangle,
        className: 'text-destructive bg-destructive/10',
    },
    disconnected: { icon: WifiOff, className: 'text-warning bg-warning/12' },
    internal_review: {
        icon: ClipboardCheck,
        className: 'text-primary bg-primary/10',
    },
    waiting: { icon: Clock, className: 'text-info bg-info/10' },
    missing_media: { icon: ImageOff, className: 'text-foreground/60 bg-muted' },
} as const;

const chartTooltipStyle = {
    borderRadius: '1rem',
    border: '1px solid var(--color-border)',
    background: 'var(--color-popover)',
    color: 'var(--color-popover-foreground)',
    fontSize: 12,
    padding: '8px 12px',
    boxShadow: 'var(--shadow-md)',
};

const axisTick = {
    fontSize: 11,
    fontFamily: 'var(--font-mono)',
    fill: 'var(--color-muted-foreground)',
};

function greeting(): string {
    const hour = new Date().getHours();

    if (hour < 12) {
        return 'Good morning';
    }

    return hour < 18 ? 'Good afternoon' : 'Good evening';
}

export default function Dashboard({
    summary,
    invoiceTotals,
    upcomingPosts,
    needsAttentionItems,
    clientHealth,
    unapprovedDraftsList,
    publishReliability,
    liveCampaigns,
}: DashboardProps) {
    const { currency, auth } = usePage<{ currency: string; auth: Auth }>()
        .props;
    // Phase 17: the Overview's charts hand off to chapter 06 with that section pre-selected.
    const canViewAnalytics = auth.can?.viewAnalytics === true;
    const firstName = auth.user.name.split(' ')[0];

    const currencyFormatter = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    });
    const compactCurrency = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
        notation: 'compact',
        maximumFractionDigits: 1,
    });

    const hasInvoiceData = invoiceTotals.some(
        (month) => month.invoiced > 0 || month.paid > 0,
    );
    const totalInvoiced = invoiceTotals.reduce(
        (sum, month) => sum + month.invoiced,
        0,
    );
    const totalPaid = invoiceTotals.reduce((sum, month) => sum + month.paid, 0);

    const publishReliabilityMonthly = publishReliability.monthly;
    const hasPublishReliabilityData = publishReliabilityMonthly.some(
        (month) => month.failed > 0 || month.published > 0,
    );

    return (
        <>
            <Head title="Home" />
            <Page>
                <ChapterHero
                    eyebrow={todayFormatter.format(new Date())}
                    title={
                        <>
                            {greeting()}, <em>{firstName}</em>.
                        </>
                    }
                    description="Here's the studio today — what's blocked, what's going out, and how every client is tracking."
                    actions={
                        <>
                            <Button variant="spark" size="lg" asChild>
                                <Link href={needsAttention()}>
                                    Review the queue
                                    <ArrowRight />
                                </Link>
                            </Button>
                            <Button variant="on-ink" size="lg" asChild>
                                <Link href={calendar()}>
                                    <CalendarDays />
                                    Calendar
                                </Link>
                            </Button>
                        </>
                    }
                    aside={
                        <HeroStat
                            icon={AlertTriangle}
                            eyebrow="Needs attention"
                            value={String(summary.needsAttention).padStart(
                                2,
                                '0',
                            )}
                            caption={
                                summary.needsAttention === 1
                                    ? 'Decision waiting on you'
                                    : 'Decisions waiting on you'
                            }
                            href={needsAttention().url}
                        />
                    }
                />

                <HomeViewTabs
                    attentionCount={summary.needsAttention}
                    className="animate-rise"
                />

                <StatStrip
                    className="animate-rise [animation-delay:80ms]"
                    items={[
                        {
                            label: 'Next 24 hours',
                            value: summary.scheduledNext24h,
                            caption: 'Posts going out',
                            render: (cell) => (
                                <Link
                                    href={calendar()}
                                    className="hover:bg-muted/50 block h-full rounded-lg transition-colors"
                                >
                                    {cell}
                                </Link>
                            ),
                        },
                        {
                            label: 'Unapproved',
                            value: summary.unapprovedDrafts,
                            caption: 'Drafts awaiting sign-off',
                        },
                        {
                            label: 'Active clients',
                            value: summary.activeClients,
                            caption: `${summary.brands} brands on the roster`,
                            render: (cell) => (
                                <Link
                                    href={clientsIndex()}
                                    className="hover:bg-muted/50 block h-full rounded-lg transition-colors"
                                >
                                    {cell}
                                </Link>
                            ),
                        },
                        {
                            label: 'Scheduled',
                            value: summary.scheduledPosts,
                            caption: 'Posts in the pipeline',
                        },
                    ]}
                />

                <div className="grid grid-cols-1 gap-5 lg:grid-cols-[1.45fr_1fr]">
                    <Panel
                        eyebrow="Queue"
                        title="Needs attention"
                        actions={
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={needsAttention()}>
                                    View all
                                    <ArrowRight />
                                </Link>
                            </Button>
                        }
                        bodyClassName="p-2"
                    >
                        {needsAttentionItems.length > 0 ? (
                            <ul className="flex flex-col">
                                {needsAttentionItems.map((row) => {
                                    const meta = attentionMeta[row.type];
                                    const href = row.post_id
                                        ? editPost(row.post_id)
                                        : row.client_id
                                          ? showClient(row.client_id)
                                          : undefined;
                                    const content = (
                                        <>
                                            <span
                                                className={`flex size-8 shrink-0 items-center justify-center rounded-lg ${meta.className}`}
                                            >
                                                <meta.icon className="size-4" />
                                            </span>
                                            <span className="flex min-w-0 flex-1 flex-col">
                                                <span className="truncate text-sm font-medium">
                                                    {row.title}
                                                </span>
                                                <span className="text-muted-foreground truncate text-xs">
                                                    {row.subtitle}
                                                </span>
                                            </span>
                                            <span className="eyebrow text-muted-foreground hidden shrink-0 sm:inline">
                                                {row.client_name ??
                                                    'Unknown client'}
                                            </span>
                                        </>
                                    );

                                    return (
                                        <li key={row.key}>
                                            {href ? (
                                                <Link
                                                    href={href}
                                                    className="hover:bg-muted/70 flex items-center gap-3 rounded-xl px-3 py-2.5 transition-colors"
                                                >
                                                    {content}
                                                </Link>
                                            ) : (
                                                <div className="flex items-center gap-3 px-3 py-2.5">
                                                    {content}
                                                </div>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        ) : (
                            <EmptyState
                                compact
                                icon={CheckCircle2}
                                title="All clear."
                                description="Nothing is blocked right now — enjoy it."
                                className="m-3 border-0"
                            />
                        )}
                    </Panel>

                    <Panel
                        eyebrow="Retainers"
                        title="Client health"
                        icon={HeartPulse}
                        bodyClassName="p-0"
                    >
                        {clientHealth.length > 0 ? (
                            <ul className="divide-border/70 divide-y">
                                {clientHealth.map((client) => (
                                    <li key={client.id}>
                                        <Link
                                            href={showClient(client.id)}
                                            title={client.reason}
                                            className="group hover:bg-muted/50 flex items-center justify-between gap-3 px-5 py-3 transition-colors"
                                        >
                                            <span className="flex min-w-0 flex-col">
                                                <span className="group-hover:text-primary truncate text-sm font-medium">
                                                    {client.name}
                                                </span>
                                                <span className="text-muted-foreground truncate font-mono text-[11px] tabular-nums">
                                                    {client.post_count} posts
                                                    {client.retainer_amount !==
                                                    null
                                                        ? ` · ${currencyFormatter.format(client.retainer_amount)}${client.billing_cycle_label ? ` / ${client.billing_cycle_label.toLowerCase()}` : ''}`
                                                        : ''}
                                                </span>
                                            </span>
                                            <StatusPill
                                                kind="health"
                                                value={client.status}
                                                label={client.status_label}
                                            />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <EmptyState
                                compact
                                icon={Users}
                                title="No clients yet."
                                className="m-4"
                            />
                        )}
                    </Panel>
                </div>

                {auth.user.role !== 'client_reviewer' && (
                    <Deferred
                        data="liveCampaigns"
                        fallback={<Skeleton className="h-40 rounded-2xl" />}
                    >
                        <LiveCampaignsPanel
                            items={liveCampaigns?.items ?? []}
                            total={liveCampaigns?.total ?? 0}
                        />
                    </Deferred>
                )}

                <div className="grid grid-cols-1 gap-5 lg:grid-cols-[1.45fr_1fr]">
                    {invoiceTotals.length > 0 && (
                        <Panel
                            eyebrow={`Revenue (${currency}) — last ${invoiceTotals.length} months`}
                            title="Invoiced vs. paid"
                            icon={LineChart}
                            actions={
                                hasInvoiceData && (
                                    <div className="flex flex-col items-end">
                                        <span className="font-display text-2xl leading-none font-bold tabular-nums">
                                            {compactCurrency.format(totalPaid)}
                                        </span>
                                        <span className="text-muted-foreground text-[11px]">
                                            of{' '}
                                            {compactCurrency.format(
                                                totalInvoiced,
                                            )}{' '}
                                            invoiced
                                        </span>
                                    </div>
                                )
                            }
                        >
                            {hasInvoiceData ? (
                                <div className="h-64 w-full">
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <AreaChart
                                            data={invoiceTotals}
                                            margin={{
                                                top: 8,
                                                right: 8,
                                                left: 0,
                                                bottom: 0,
                                            }}
                                        >
                                            <defs>
                                                <linearGradient
                                                    id="invoiceGradient"
                                                    x1="0"
                                                    y1="0"
                                                    x2="0"
                                                    y2="1"
                                                >
                                                    <stop
                                                        offset="0%"
                                                        stopColor="var(--color-chart-1)"
                                                        stopOpacity={0.22}
                                                    />
                                                    <stop
                                                        offset="100%"
                                                        stopColor="var(--color-chart-1)"
                                                        stopOpacity={0}
                                                    />
                                                </linearGradient>
                                            </defs>
                                            <CartesianGrid
                                                vertical={false}
                                                stroke="var(--color-border)"
                                                strokeDasharray="2 4"
                                            />
                                            <XAxis
                                                dataKey="label"
                                                tick={axisTick}
                                                tickLine={false}
                                                axisLine={false}
                                                dy={6}
                                            />
                                            <YAxis
                                                tick={axisTick}
                                                tickLine={false}
                                                axisLine={false}
                                                width={56}
                                                tickFormatter={(
                                                    value: number,
                                                ) =>
                                                    compactCurrency.format(
                                                        value,
                                                    )
                                                }
                                            />
                                            <Tooltip
                                                cursor={{
                                                    stroke: 'var(--color-foreground)',
                                                    strokeOpacity: 0.2,
                                                    strokeWidth: 1,
                                                }}
                                                contentStyle={chartTooltipStyle}
                                                formatter={(value) =>
                                                    currencyFormatter.format(
                                                        Number(value),
                                                    )
                                                }
                                            />
                                            <Legend
                                                verticalAlign="top"
                                                align="right"
                                                height={28}
                                                iconType="circle"
                                                iconSize={8}
                                                wrapperStyle={{ fontSize: 12 }}
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="invoiced"
                                                name="Invoiced"
                                                stroke="var(--color-chart-1)"
                                                strokeWidth={2}
                                                fill="url(#invoiceGradient)"
                                                activeDot={{
                                                    r: 4,
                                                    strokeWidth: 2,
                                                    stroke: 'var(--color-card)',
                                                }}
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="paid"
                                                name="Paid"
                                                stroke="var(--color-chart-2)"
                                                strokeWidth={2}
                                                fill="none"
                                                activeDot={{
                                                    r: 4,
                                                    strokeWidth: 2,
                                                    stroke: 'var(--color-card)',
                                                }}
                                            />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </div>
                            ) : (
                                <EmptyState
                                    compact
                                    title="No invoices yet."
                                    description="Raised invoices will chart here month by month."
                                    className="border-0"
                                />
                            )}
                            {canViewAnalytics && (
                                <FullAnalyticsLink section="revenue" />
                            )}
                        </Panel>
                    )}

                    <Panel
                        eyebrow="Next 7 days"
                        title="Going out"
                        actions={
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={calendar()}>
                                    Calendar
                                    <ArrowRight />
                                </Link>
                            </Button>
                        }
                        bodyClassName="p-2"
                        className={
                            invoiceTotals.length === 0
                                ? 'lg:col-span-2'
                                : undefined
                        }
                    >
                        {upcomingPosts.length > 0 ? (
                            <ul className="flex flex-col">
                                {upcomingPosts.map((occurrence) => {
                                    const date = occurrence.date
                                        ? new Date(occurrence.date)
                                        : null;

                                    return (
                                        <li key={occurrence.target_id}>
                                            <div className="flex items-center gap-4 rounded-xl px-3 py-2.5">
                                                <span className="border-border flex w-11 shrink-0 flex-col items-center border-r pr-4">
                                                    <span className="font-display text-xl leading-none font-bold tabular-nums">
                                                        {date
                                                            ? dayFormatter.format(
                                                                  date,
                                                              )
                                                            : '—'}
                                                    </span>
                                                    <span className="eyebrow text-muted-foreground text-[9.5px]">
                                                        {date
                                                            ? weekdayFormatter.format(
                                                                  date,
                                                              )
                                                            : ''}
                                                    </span>
                                                </span>
                                                <span className="flex min-w-0 flex-1 flex-col">
                                                    <span className="truncate text-sm font-medium">
                                                        {occurrence.client_name ??
                                                            'Unknown client'}
                                                    </span>
                                                    <span className="text-muted-foreground truncate text-xs capitalize">
                                                        {occurrence.platform ??
                                                            'Unknown platform'}
                                                        {occurrence.handle
                                                            ? ` · ${occurrence.handle}`
                                                            : ''}
                                                    </span>
                                                </span>
                                                <span className="text-muted-foreground shrink-0 font-mono text-xs tabular-nums">
                                                    {occurrence.time}
                                                </span>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        ) : (
                            <EmptyState
                                compact
                                icon={CalendarDays}
                                title="A quiet week."
                                description="No posts are scheduled in the next 7 days."
                                className="m-3 border-0"
                            />
                        )}
                    </Panel>
                </div>

                {(hasPublishReliabilityData ||
                    unapprovedDraftsList.length > 0) && (
                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-[1.45fr_1fr]">
                        {hasPublishReliabilityData && (
                            <Panel
                                eyebrow={`Reliability — last ${publishReliabilityMonthly.length} months`}
                                title="Publish attempts"
                                icon={RefreshCw}
                                actions={
                                    publishReliability.averageAttemptsToSuccess !==
                                        null && (
                                        <div className="flex flex-col items-end">
                                            <span className="font-display text-2xl leading-none font-bold tabular-nums">
                                                {publishReliability.averageAttemptsToSuccess.toFixed(
                                                    2,
                                                )}
                                            </span>
                                            <span className="text-muted-foreground text-[11px]">
                                                avg. attempts to publish
                                            </span>
                                        </div>
                                    )
                                }
                            >
                                <div className="h-64 w-full">
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <AreaChart
                                            data={publishReliabilityMonthly}
                                            margin={{
                                                top: 8,
                                                right: 8,
                                                left: 0,
                                                bottom: 0,
                                            }}
                                        >
                                            <defs>
                                                <linearGradient
                                                    id="publishedGradient"
                                                    x1="0"
                                                    y1="0"
                                                    x2="0"
                                                    y2="1"
                                                >
                                                    <stop
                                                        offset="0%"
                                                        stopColor="var(--color-success)"
                                                        stopOpacity={0.2}
                                                    />
                                                    <stop
                                                        offset="100%"
                                                        stopColor="var(--color-success)"
                                                        stopOpacity={0}
                                                    />
                                                </linearGradient>
                                            </defs>
                                            <CartesianGrid
                                                vertical={false}
                                                stroke="var(--color-border)"
                                                strokeDasharray="2 4"
                                            />
                                            <XAxis
                                                dataKey="label"
                                                tick={axisTick}
                                                tickLine={false}
                                                axisLine={false}
                                                dy={6}
                                            />
                                            <YAxis
                                                tick={axisTick}
                                                tickLine={false}
                                                axisLine={false}
                                                allowDecimals={false}
                                                width={32}
                                            />
                                            <Tooltip
                                                cursor={{
                                                    stroke: 'var(--color-foreground)',
                                                    strokeOpacity: 0.2,
                                                    strokeWidth: 1,
                                                }}
                                                contentStyle={chartTooltipStyle}
                                            />
                                            <Legend
                                                verticalAlign="top"
                                                align="right"
                                                height={28}
                                                iconType="circle"
                                                iconSize={8}
                                                wrapperStyle={{ fontSize: 12 }}
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="published"
                                                name="Published"
                                                stroke="var(--color-success)"
                                                strokeWidth={2}
                                                fill="url(#publishedGradient)"
                                                activeDot={{
                                                    r: 4,
                                                    strokeWidth: 2,
                                                    stroke: 'var(--color-card)',
                                                }}
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="failed"
                                                name="Failed"
                                                stroke="var(--color-destructive)"
                                                strokeWidth={2}
                                                strokeDasharray="4 3"
                                                fill="none"
                                                activeDot={{
                                                    r: 4,
                                                    strokeWidth: 2,
                                                    stroke: 'var(--color-card)',
                                                }}
                                            />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </div>
                                {canViewAnalytics && (
                                    <FullAnalyticsLink section="posts" />
                                )}
                            </Panel>
                        )}

                        {unapprovedDraftsList.length > 0 && (
                            <Panel
                                eyebrow="In review"
                                title="Unapproved drafts"
                                icon={FilePenLine}
                                bodyClassName="p-0"
                                className={
                                    !hasPublishReliabilityData
                                        ? 'lg:col-span-2'
                                        : undefined
                                }
                            >
                                <ul className="divide-border/70 divide-y">
                                    {unapprovedDraftsList.map((draft) => (
                                        <li key={draft.post_id}>
                                            <Link
                                                href={editPost(draft.post_id)}
                                                className="group hover:bg-muted/50 flex items-center justify-between gap-3 px-5 py-3 transition-colors"
                                            >
                                                <span className="flex min-w-0 flex-col">
                                                    <span className="group-hover:text-primary truncate text-sm font-medium">
                                                        {draft.title}
                                                    </span>
                                                    <span className="text-muted-foreground truncate text-xs">
                                                        {draft.client_name ??
                                                            'Unknown client'}
                                                    </span>
                                                </span>
                                                <StatusPill
                                                    kind="post"
                                                    value={draft.status}
                                                    label={draft.status_label}
                                                />
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </Panel>
                        )}
                    </div>
                )}
            </Page>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Home',
            href: dashboard(),
        },
    ],
};

/** "See full analytics" — opens 06 Analytics with the matching section pre-selected. */
function FullAnalyticsLink({ section }: { section: 'posts' | 'revenue' }) {
    return (
        <div className="border-border/70 mt-4 flex justify-end border-t pt-3">
            <Button variant="link" size="sm" className="h-auto px-0" asChild>
                <Link href={analyticsIndex({ query: { metrics: [section] } })}>
                    See full analytics
                    <ArrowRight />
                </Link>
            </Button>
        </div>
    );
}
