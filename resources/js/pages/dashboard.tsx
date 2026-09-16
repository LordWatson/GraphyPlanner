import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CalendarDays, Clock, ImageOff, Users, WifiOff } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

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
};

const dateFormatter = new Intl.DateTimeFormat('en-US', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
});

const currencyFormatter = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 0,
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
}: DashboardProps) {
    const summaryCards = [
        { icon: AlertTriangle, label: 'Needs attention', hint: 'Items that need a decision today', value: summary.needsAttention },
        { icon: CalendarDays, label: 'Scheduled next 24h', hint: 'Posts going out soon', value: summary.scheduledNext24h },
        { icon: ImageOff, label: 'Unapproved drafts', hint: 'Not yet approved or scheduled', value: summary.unapprovedDrafts },
        { icon: Users, label: 'Active clients', hint: 'Clients currently active', value: summary.activeClients },
    ];

    const hasInvoiceData = invoiceTotals.some((month) => month.invoiced > 0 || month.paid > 0);

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="relative overflow-hidden rounded-xl border border-border p-6 shadow-lg shadow-primary/10">
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 -z-10 bg-gradient-brand opacity-[0.14]"
                    />
                    <div className="flex flex-col gap-1">
                        <h1 className="font-serif text-2xl font-bold tracking-tight sm:text-3xl">
                            <span className="text-gradient-brand">Studio overview</span>
                        </h1>
                        <p className="max-w-xl text-sm text-muted-foreground">
                            A snapshot of every client, brand, and post moving through the pipeline right now.
                        </p>
                    </div>
                </div>
                <div className="grid gap-4 md:grid-cols-4">
                    {summaryCards.map(({ icon: Icon, label, hint, value }) => (
                        <div
                            key={label}
                            className="relative flex flex-col gap-2 overflow-hidden rounded-xl border border-border bg-card p-4 shadow-sm transition-shadow hover:shadow-md"
                        >
                            <span
                                aria-hidden
                                className="absolute inset-x-0 top-0 h-1 bg-gradient-brand"
                            />
                            <div className="flex items-center gap-2 text-muted-foreground">
                                <span className="flex size-8 items-center justify-center rounded-md bg-gradient-brand text-white shadow-sm">
                                    <Icon className="size-4" />
                                </span>
                                <span className="text-xs">{label}</span>
                            </div>
                            <span className="text-3xl font-bold tabular-nums">{value}</span>
                            <span className="text-xs text-muted-foreground">{hint}</span>
                        </div>
                    ))}
                </div>

                {(needsAttentionItems.length > 0 || clientHealth.length > 0) && (
                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="relative flex flex-col gap-4 overflow-hidden rounded-xl border border-border bg-card p-4 shadow-sm">
                            <span aria-hidden className="absolute inset-x-0 top-0 h-1 bg-gradient-brand" />
                            <div className="flex items-center justify-between gap-2">
                                <div className="flex flex-col gap-1">
                                    <h2 className="text-sm font-semibold">Needs attention</h2>
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
                                                className="flex items-center justify-between gap-3 rounded-md border border-border px-3 py-2 hover:bg-muted/50"
                                            >
                                                {content}
                                            </Link>
                                        ) : (
                                            <div key={row.key} className="flex items-center justify-between gap-3 rounded-md border border-border px-3 py-2">
                                                {content}
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <p className="py-8 text-center text-xs text-muted-foreground">Nothing needs attention right now.</p>
                            )}
                        </div>

                        <div className="relative flex flex-col gap-4 overflow-hidden rounded-xl border border-border bg-card p-4 shadow-sm">
                            <span aria-hidden className="absolute inset-x-0 top-0 h-1 bg-gradient-brand" />
                            <div className="flex flex-col gap-1">
                                <h2 className="text-sm font-semibold">Client health</h2>
                                <p className="text-xs text-muted-foreground">Retainer status across every active client</p>
                            </div>
                            {clientHealth.length > 0 ? (
                                <ul className="flex flex-col divide-y divide-border">
                                    {clientHealth.map((client) => (
                                        <li key={client.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                            <Link href={showClient(client.id)} className="flex flex-col hover:underline">
                                                <span className="font-medium">{client.name}</span>
                                                <span className="text-xs text-muted-foreground">
                                                    {client.post_count} posts
                                                    {client.retainer_amount !== null
                                                        ? ` · ${currencyFormatter.format(client.retainer_amount)}${client.billing_cycle_label ? `/${client.billing_cycle_label.toLowerCase()}` : ''}`
                                                        : ''}
                                                </span>
                                            </Link>
                                            <Badge variant={healthBadgeVariant[client.status]} className="whitespace-nowrap text-[10px]">
                                                {client.status_label}
                                            </Badge>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="py-8 text-center text-xs text-muted-foreground">No clients yet.</p>
                            )}
                        </div>
                    </div>
                )}

                {(invoiceTotals.length > 0 || upcomingPosts.length > 0) && (
                    <div className="grid gap-4 md:grid-cols-2">
                        {invoiceTotals.length > 0 && (
                            <div className="relative flex flex-col gap-4 overflow-hidden rounded-xl border border-border bg-card p-4 shadow-sm">
                                <span
                                    aria-hidden
                                    className="absolute inset-x-0 top-0 h-1 bg-gradient-brand"
                                />
                                <div className="flex flex-col gap-1">
                                    <h2 className="text-sm font-semibold">Invoice revenue</h2>
                                    <p className="text-xs text-muted-foreground">
                                        Invoiced vs. paid amounts over the last {invoiceTotals.length} months
                                    </p>
                                </div>
                                {hasInvoiceData ? (
                                    <div className="h-64 w-full">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <BarChart data={invoiceTotals}>
                                                <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" />
                                                <XAxis dataKey="label" tick={{ fontSize: 12 }} tickLine={false} axisLine={false} />
                                                <YAxis
                                                    tick={{ fontSize: 12 }}
                                                    tickLine={false}
                                                    axisLine={false}
                                                    tickFormatter={(value: number) => currencyFormatter.format(value)}
                                                />
                                                <Tooltip formatter={(value) => currencyFormatter.format(Number(value))} />
                                                <Legend wrapperStyle={{ fontSize: 12 }} />
                                                <Bar dataKey="invoiced" name="Invoiced" fill="var(--color-chart-1)" radius={[4, 4, 0, 0]} />
                                                <Bar dataKey="paid" name="Paid" fill="var(--color-chart-2)" radius={[4, 4, 0, 0]} />
                                            </BarChart>
                                        </ResponsiveContainer>
                                    </div>
                                ) : (
                                    <p className="py-8 text-center text-xs text-muted-foreground">
                                        No invoices raised in this period yet.
                                    </p>
                                )}
                            </div>
                        )}
                        <div className="relative flex flex-col gap-4 overflow-hidden rounded-xl border border-border bg-card p-4 shadow-sm">
                            <span
                                aria-hidden
                                className="absolute inset-x-0 top-0 h-1 bg-gradient-brand"
                            />
                            <div className="flex items-center justify-between gap-2">
                                <div className="flex flex-col gap-1">
                                    <h2 className="text-sm font-semibold">Upcoming posts</h2>
                                    <p className="text-xs text-muted-foreground">Scheduled in the next 7 days</p>
                                </div>
                                <Link href={calendar()} className="text-xs font-medium text-primary hover:underline">
                                    View calendar
                                </Link>
                            </div>
                            {upcomingPosts.length > 0 ? (
                                <ul className="flex flex-col divide-y divide-border">
                                    {upcomingPosts.map((occurrence) => (
                                        <li key={occurrence.target_id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                            <div className="flex flex-col">
                                                <span className="font-medium">{occurrence.client_name ?? 'Unknown client'}</span>
                                                <span className="text-xs text-muted-foreground">
                                                    {occurrence.platform ?? 'Unknown platform'}
                                                    {occurrence.handle ? ` · ${occurrence.handle}` : ''}
                                                </span>
                                            </div>
                                            <span className="whitespace-nowrap text-xs text-muted-foreground">
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

                {unapprovedDraftsList.length > 0 && (
                    <div className="relative flex flex-col gap-4 overflow-hidden rounded-xl border border-border bg-card p-4 shadow-sm">
                        <span aria-hidden className="absolute inset-x-0 top-0 h-1 bg-gradient-brand" />
                        <div className="flex flex-col gap-1">
                            <h2 className="text-sm font-semibold">Unapproved drafts</h2>
                            <p className="text-xs text-muted-foreground">Posts still waiting on review, changes, or approval</p>
                        </div>
                        <ul className="flex flex-col divide-y divide-border">
                            {unapprovedDraftsList.map((draft) => (
                                <li key={draft.post_id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                    <Link href={editPost(draft.post_id)} className="flex flex-col hover:underline">
                                        <span className="font-medium">{draft.title}</span>
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
