import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, CalendarDays, Clock, ImageOff, RefreshCw, Users, WifiOff } from 'lucide-react';
import { Area, AreaChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

import { Badge } from '@/components/ui/badge';
import { calendar, dashboard, needsAttention } from '@/routes';
import { edit as editPost } from '@/routes/posts';
import { show as showClient } from '@/routes/clients';

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
    type: 'failed' | 'disconnected' | 'waiting' | 'missing_media';
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
};

const dateFormatter = new Intl.DateTimeFormat('en-US', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
});

const attentionIcons = {
    failed: AlertTriangle,
    disconnected: WifiOff,
    waiting: Clock,
    missing_media: ImageOff,
} as const;

const healthBadgeVariant: Record<ClientHealthRow['status'], 'destructive' | 'warning' | 'success'> = {
    red: 'destructive',
    amber: 'warning',
    green: 'success',
};

export default function Dashboard({
    summary,
    invoiceTotals,
    upcomingPosts,
    needsAttentionItems,
    clientHealth,
    unapprovedDraftsList,
    publishReliability,
}: DashboardProps) {
    const { currency } = usePage().props;

    const currencyFormatter = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    });

    const summaryCards = [
        { icon: AlertTriangle, label: 'Needs attention', hint: 'Items that need a decision today', value: summary.needsAttention },
        { icon: CalendarDays, label: 'Scheduled next 24h', hint: 'Posts going out soon', value: summary.scheduledNext24h },
        { icon: ImageOff, label: 'Unapproved drafts', hint: 'Not yet approved or scheduled', value: summary.unapprovedDrafts },
        { icon: Users, label: 'Active clients', hint: 'Clients currently active', value: summary.activeClients },
    ];

    const hasInvoiceData = invoiceTotals.some((month) => month.invoiced > 0 || month.paid > 0);
    const totalInvoiced = invoiceTotals.reduce((sum, month) => sum + month.invoiced, 0);
    const totalPaid = invoiceTotals.reduce((sum, month) => sum + month.paid, 0);

    const publishReliabilityMonthly = publishReliability.monthly;
    const hasPublishReliabilityData = publishReliabilityMonthly.some(
        (month) => month.failed > 0 || month.published > 0,
    );

    const [heroStat, ...restStats] = summaryCards;

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                {/* Asymmetric hero: a tall brand panel on the left, a stacked KPI column on the right —
                    deliberately broken out of the old symmetric 4-up card grid. */}
                <div className="grid gap-4 lg:grid-cols-[1.6fr_1fr]">
                    <div className="bg-noise relative flex flex-col justify-between overflow-hidden rounded-2xl bg-gradient-brand-animated p-8 text-primary-foreground shadow-agency">
                        <span
                            aria-hidden
                            className="pointer-events-none absolute -top-16 -right-16 size-56 rounded-full bg-white/10 blur-2xl"
                        />
                        <span
                            aria-hidden
                            className="pointer-events-none absolute inset-0 bg-[radial-gradient(white_1px,transparent_1px)] bg-[size:22px_22px] opacity-[0.08]"
                        />
                        <div className="relative flex flex-col gap-3">
                            <span className="inline-flex w-fit items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-[10px] font-medium tracking-widest uppercase">
                                Studio overview
                            </span>
                            <h1 className="max-w-md font-serif text-3xl font-semibold tracking-tight italic sm:text-4xl">
                                Everything your team is shipping, right now.
                            </h1>
                            <p className="max-w-md text-sm text-primary-foreground/80">
                                A snapshot of every client, brand, and post moving through the pipeline.
                            </p>
                        </div>
                        <div className="relative mt-8 flex items-end justify-between gap-4 border-t border-white/15 pt-6">
                            <div className="flex items-center gap-3">
                                <heroStat.icon className="size-9 rounded-lg bg-white/15 p-2" />
                                <div className="flex flex-col">
                                    <span className="text-4xl font-bold tabular-nums">{heroStat.value}</span>
                                    <span className="text-xs text-primary-foreground/80">{heroStat.label}</span>
                                </div>
                            </div>
                            <Link
                                href={needsAttention()}
                                className="rounded-full bg-white/15 px-4 py-2 text-xs font-medium whitespace-nowrap hover:bg-white/25"
                            >
                                Review now →
                            </Link>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-1">
                        {restStats.map(({ icon: Icon, label, hint, value }) => (
                            <div
                                key={label}
                                className="group relative flex flex-1 items-center gap-4 overflow-hidden rounded-2xl border border-border bg-card p-4 shadow-sm transition-colors hover:border-primary/30"
                            >
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent text-accent-foreground">
                                    <Icon className="size-5" />
                                </span>
                                <div className="flex flex-col">
                                    <span className="text-2xl font-bold tabular-nums">{value}</span>
                                    <span className="text-xs font-medium">{label}</span>
                                    <span className="text-[11px] text-muted-foreground">{hint}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {(needsAttentionItems.length > 0 || clientHealth.length > 0) && (
                    <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
                        <div className="relative flex flex-col gap-4 rounded-2xl border border-destructive/20 bg-card p-5 shadow-sm">
                            <div className="flex items-center justify-between gap-2">
                                <div className="flex flex-col gap-1">
                                    <h2 className="font-serif text-lg font-semibold italic">Needs attention</h2>
                                    <p className="text-xs text-muted-foreground">The work that needs a decision today</p>
                                </div>
                                <Link href={needsAttention()} className="text-xs font-medium text-primary hover:underline">
                                    View all
                                </Link>
                            </div>
                            {needsAttentionItems.length > 0 ? (
                                <div className="flex flex-col gap-2">
                                    {needsAttentionItems.map((row) => {
                                        const Icon = attentionIcons[row.type];
                                        const href = row.post_id
                                            ? editPost(row.post_id)
                                            : row.client_id
                                                ? showClient(row.client_id)
                                                : undefined;
                                        const content = (
                                            <>
                                                <div className="flex items-center gap-3">
                                                    <Icon className="size-4 shrink-0 text-muted-foreground" />
                                                    <div className="flex flex-col">
                                                        <span className="text-sm font-medium">{row.title}</span>
                                                        <span className="text-xs text-muted-foreground">{row.subtitle}</span>
                                                    </div>
                                                </div>
                                                <span className="whitespace-nowrap text-xs text-muted-foreground">
                                                    {row.client_name ?? 'Unknown client'}
                                                </span>
                                            </>
                                        );

                                        return href ? (
                                            <Link
                                                key={row.key}
                                                href={href}
                                                className="flex items-center justify-between gap-3 rounded-xl border border-border px-3 py-2.5 transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-sm"
                                            >
                                                {content}
                                            </Link>
                                        ) : (
                                            <div key={row.key} className="flex items-center justify-between gap-3 rounded-xl border border-border px-3 py-2.5">
                                                {content}
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <p className="py-8 text-center text-xs text-muted-foreground">Nothing needs attention right now.</p>
                            )}
                        </div>

                        <div className="bg-noise relative flex flex-col gap-4 overflow-hidden rounded-2xl bg-gradient-brand p-5 text-primary-foreground shadow-agency">
                            <div className="flex flex-col gap-1">
                                <h2 className="font-serif text-lg font-semibold italic">Client health</h2>
                                <p className="text-xs text-primary-foreground/80">Retainer status across every active client</p>
                            </div>
                            {clientHealth.length > 0 ? (
                                <ul className="flex flex-col divide-y divide-white/15">
                                    {clientHealth.map((client) => (
                                        <li key={client.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                            <Link href={showClient(client.id)} className="flex flex-col hover:underline">
                                                <span className="font-medium">{client.name}</span>
                                                <span className="text-xs text-primary-foreground/80">
                                                    {client.post_count} posts
                                                    {client.retainer_amount !== null
                                                        ? ` · ${currencyFormatter.format(client.retainer_amount)}${client.billing_cycle_label ? `/${client.billing_cycle_label.toLowerCase()}` : ''}`
                                                        : ''}
                                                </span>
                                            </Link>
                                            <Badge variant={healthBadgeVariant[client.status]} className="whitespace-nowrap bg-white/90 text-[10px]">
                                                {client.status_label}
                                            </Badge>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="py-8 text-center text-xs text-primary-foreground/80">No clients yet.</p>
                            )}
                        </div>
                    </div>
                )}

                {(invoiceTotals.length > 0 || upcomingPosts.length > 0) && (
                    <div className="grid gap-4 md:grid-cols-2">
                        {invoiceTotals.length > 0 && (
                            <div className="relative flex flex-col gap-4 overflow-hidden rounded-2xl border border-border bg-card p-5 shadow-sm">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="flex flex-col gap-1">
                                        <h2 className="font-serif text-lg font-semibold italic">Invoice revenue</h2>
                                        <p className="text-xs text-muted-foreground">
                                            Invoiced vs. paid amounts over the last {invoiceTotals.length} months
                                        </p>
                                    </div>
                                    {hasInvoiceData && (
                                        <div className="flex flex-col items-end">
                                            <span className="text-2xl font-bold tabular-nums text-gradient-brand">
                                                {currencyFormatter.format(totalPaid)}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                of {currencyFormatter.format(totalInvoiced)} invoiced
                                            </span>
                                        </div>
                                    )}
                                </div>
                                {hasInvoiceData ? (
                                    <div className="h-64 w-full">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <AreaChart data={invoiceTotals}>
                                                <defs>
                                                    <linearGradient id="invoiceGradient" x1="0" y1="0" x2="0" y2="1">
                                                        <stop offset="0%" stopColor="var(--color-chart-1)" stopOpacity={0.55} />
                                                        <stop offset="100%" stopColor="var(--color-chart-1)" stopOpacity={0.02} />
                                                    </linearGradient>
                                                    <linearGradient id="paidGradient" x1="0" y1="0" x2="0" y2="1">
                                                        <stop offset="0%" stopColor="var(--color-chart-2)" stopOpacity={0.85} />
                                                        <stop offset="100%" stopColor="var(--color-chart-2)" stopOpacity={0.05} />
                                                    </linearGradient>
                                                </defs>
                                                <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" />
                                                <XAxis dataKey="label" tick={{ fontSize: 12 }} tickLine={false} axisLine={false} />
                                                <YAxis
                                                    tick={{ fontSize: 12 }}
                                                    tickLine={false}
                                                    axisLine={false}
                                                    tickFormatter={(value: number) => currencyFormatter.format(value)}
                                                />
                                                <Tooltip
                                                    cursor={{ stroke: 'var(--color-primary)', strokeWidth: 1, strokeDasharray: '4 4' }}
                                                    contentStyle={{
                                                        borderRadius: 'var(--radius)',
                                                        borderColor: 'var(--color-border)',
                                                        fontSize: 12,
                                                        boxShadow: 'var(--shadow-lg, 0 10px 30px -10px rgb(0 0 0 / 0.2))',
                                                    }}
                                                    formatter={(value) => currencyFormatter.format(Number(value))}
                                                />
                                                <Legend wrapperStyle={{ fontSize: 12 }} iconType="circle" />
                                                <Area type="monotone" dataKey="invoiced" name="Invoiced" stroke="var(--color-chart-1)" strokeWidth={2.5} fill="url(#invoiceGradient)" activeDot={{ r: 5 }} />
                                                <Area type="monotone" dataKey="paid" name="Paid" stroke="var(--color-chart-2)" strokeWidth={2.5} fill="url(#paidGradient)" activeDot={{ r: 5 }} />
                                            </AreaChart>
                                        </ResponsiveContainer>
                                    </div>
                                ) : (
                                    <p className="py-8 text-center text-xs text-muted-foreground">
                                        No invoices raised in this period yet.
                                    </p>
                                )}
                            </div>
                        )}
                        <div className="relative flex flex-col gap-4 overflow-hidden rounded-2xl border border-border bg-card p-5 shadow-sm">
                            <div className="flex items-center justify-between gap-2">
                                <div className="flex flex-col gap-1">
                                    <h2 className="font-serif text-lg font-semibold italic">Upcoming posts</h2>
                                    <p className="text-xs text-muted-foreground">Scheduled in the next 7 days</p>
                                </div>
                                <Link href={calendar()} className="text-xs font-medium text-primary hover:underline">
                                    View calendar
                                </Link>
                            </div>
                            {upcomingPosts.length > 0 ? (
                                <ul className="flex flex-col gap-1.5">
                                    {upcomingPosts.map((occurrence) => (
                                        <li
                                            key={occurrence.target_id}
                                            className="flex items-center justify-between gap-3 rounded-xl px-2.5 py-2 text-sm transition-colors hover:bg-muted/40"
                                        >
                                            <div className="flex items-center gap-3">
                                                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-accent text-accent-foreground">
                                                    <CalendarDays className="size-3.5" />
                                                </span>
                                                <div className="flex flex-col">
                                                    <span className="font-medium">{occurrence.client_name ?? 'Unknown client'}</span>
                                                    <span className="text-xs text-muted-foreground">
                                                        {occurrence.platform ?? 'Unknown platform'}
                                                        {occurrence.handle ? ` · ${occurrence.handle}` : ''}
                                                    </span>
                                                </div>
                                            </div>
                                            <span className="whitespace-nowrap text-xs font-medium text-muted-foreground">
                                                {occurrence.date ? dateFormatter.format(new Date(occurrence.date)) : ''} {occurrence.time}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="py-8 text-center text-xs text-muted-foreground">
                                    No posts scheduled in the next 7 days.
                                </p>
                            )}
                        </div>
                    </div>
                )}

                {(hasPublishReliabilityData || unapprovedDraftsList.length > 0) && (
                    <div className="grid gap-4 md:grid-cols-2">
                        {hasPublishReliabilityData && (
                            <div className="relative flex flex-col gap-4 overflow-hidden rounded-2xl border border-border bg-card p-5 shadow-sm">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="flex flex-col gap-1">
                                        <h2 className="font-serif text-lg font-semibold italic">Publish reliability</h2>
                                        <p className="text-xs text-muted-foreground">
                                            Failed vs. successful publish attempts over the last {publishReliabilityMonthly.length} months
                                        </p>
                                    </div>
                                    {publishReliability.averageAttemptsToSuccess !== null && (
                                        <div className="flex flex-col items-end">
                                            <span className="flex items-center gap-1 text-2xl font-bold tabular-nums text-gradient-brand">
                                                <RefreshCw className="size-4 text-primary" />
                                                {publishReliability.averageAttemptsToSuccess.toFixed(2)}
                                            </span>
                                            <span className="text-xs text-muted-foreground">avg. attempts per successful publish</span>
                                        </div>
                                    )}
                                </div>
                                <div className="h-64 w-full">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <AreaChart data={publishReliabilityMonthly}>
                                            <defs>
                                                <linearGradient id="failedGradient" x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="0%" stopColor="var(--color-destructive)" stopOpacity={0.6} />
                                                    <stop offset="100%" stopColor="var(--color-destructive)" stopOpacity={0.03} />
                                                </linearGradient>
                                                <linearGradient id="publishedGradient" x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="0%" stopColor="var(--color-chart-2)" stopOpacity={0.85} />
                                                    <stop offset="100%" stopColor="var(--color-chart-2)" stopOpacity={0.05} />
                                                </linearGradient>
                                            </defs>
                                            <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" />
                                            <XAxis dataKey="label" tick={{ fontSize: 12 }} tickLine={false} axisLine={false} />
                                            <YAxis tick={{ fontSize: 12 }} tickLine={false} axisLine={false} allowDecimals={false} />
                                            <Tooltip
                                                cursor={{ stroke: 'var(--color-primary)', strokeWidth: 1, strokeDasharray: '4 4' }}
                                                contentStyle={{
                                                    borderRadius: 'var(--radius)',
                                                    borderColor: 'var(--color-border)',
                                                    fontSize: 12,
                                                    boxShadow: 'var(--shadow-lg, 0 10px 30px -10px rgb(0 0 0 / 0.2))',
                                                }}
                                            />
                                            <Legend wrapperStyle={{ fontSize: 12 }} iconType="circle" />
                                            <Area type="monotone" dataKey="failed" name="Failed" stroke="var(--color-destructive)" strokeWidth={2.5} fill="url(#failedGradient)" activeDot={{ r: 5 }} />
                                            <Area type="monotone" dataKey="published" name="Published" stroke="var(--color-chart-2)" strokeWidth={2.5} fill="url(#publishedGradient)" activeDot={{ r: 5 }} />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </div>
                            </div>
                        )}

                        {unapprovedDraftsList.length > 0 && (
                            <div className="relative flex flex-col gap-4 overflow-hidden rounded-2xl border border-border bg-card p-5 shadow-sm">
                                <div className="flex flex-col gap-1">
                                    <h2 className="font-serif text-lg font-semibold italic">Unapproved drafts</h2>
                                    <p className="text-xs text-muted-foreground">Posts still waiting on review, changes, or approval</p>
                                </div>
                                <ul className="flex flex-col divide-y divide-border">
                                    {unapprovedDraftsList.map((draft) => (
                                        <li key={draft.post_id} className="group flex items-center justify-between gap-3 rounded-lg px-2 py-2 text-sm transition-colors hover:bg-muted/40">
                                            <Link href={editPost(draft.post_id)} className="flex flex-col">
                                                <span className="font-medium group-hover:underline">{draft.title}</span>
                                                <span className="text-xs text-muted-foreground">{draft.client_name ?? 'Unknown client'}</span>
                                            </Link>
                                            <Badge variant="outline" className="whitespace-nowrap text-[10px]">
                                                {draft.status_label}
                                            </Badge>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
