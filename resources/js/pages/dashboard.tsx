import { Head, Link } from '@inertiajs/react';
import { Building2, CalendarDays, Users } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

import { calendar, dashboard } from '@/routes';

type DashboardSummary = {
    clients: number;
    brands: number;
    scheduledPosts: number;
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

type DashboardProps = {
    summary: DashboardSummary;
    invoiceTotals: InvoiceMonthlyTotal[];
    upcomingPosts: UpcomingPostOccurrence[];
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

export default function Dashboard({ summary, invoiceTotals, upcomingPosts }: DashboardProps) {
    const summaryCards = [
        { icon: Users, label: 'Clients', hint: 'Active clients under management', value: summary.clients },
        { icon: Building2, label: 'Brands', hint: 'Brands across all clients', value: summary.brands },
        { icon: CalendarDays, label: 'Scheduled posts', hint: 'Upcoming in the next 7 days', value: summary.scheduledPosts },
    ];

    const hasInvoiceData = invoiceTotals.some((month) => month.invoiced > 0 || month.paid > 0);

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="grid gap-4 md:grid-cols-3">
                    {summaryCards.map(({ icon: Icon, label, hint, value }) => (
                        <div
                            key={label}
                            className="relative flex flex-col gap-2 overflow-hidden rounded-xl border border-border bg-card p-4 shadow-sm"
                        >
                            <span
                                aria-hidden
                                className="absolute inset-x-0 top-0 h-1 bg-gradient-brand"
                            />
                            <div className="flex items-center gap-2 text-muted-foreground">
                                <span className="flex size-7 items-center justify-center rounded-md bg-primary/10 text-primary">
                                    <Icon className="size-4" />
                                </span>
                                <span className="text-xs">{label}</span>
                            </div>
                            <span className="text-2xl font-semibold tabular-nums">{value}</span>
                            <span className="text-xs text-muted-foreground">{hint}</span>
                        </div>
                    ))}
                </div>
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
