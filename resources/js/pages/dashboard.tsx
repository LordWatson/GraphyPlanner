import { Head } from '@inertiajs/react';
import { Building2, CalendarDays, Users } from 'lucide-react';

import { dashboard } from '@/routes';

type DashboardSummary = {
    clients: number;
    brands: number;
    scheduledPosts: number;
};

type DashboardProps = {
    summary: DashboardSummary;
};

export default function Dashboard({ summary }: DashboardProps) {
    const summaryCards = [
        { icon: Users, label: 'Clients', hint: 'Active clients under management', value: summary.clients },
        { icon: Building2, label: 'Brands', hint: 'Brands across all clients', value: summary.brands },
        { icon: CalendarDays, label: 'Scheduled posts', hint: 'Upcoming in the next 7 days', value: summary.scheduledPosts },
    ];

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
                <div className="relative flex min-h-[50vh] flex-1 flex-col items-center justify-center gap-2 overflow-hidden rounded-xl border border-dashed border-border bg-card/50 p-8 text-center">
                    <div
                        aria-hidden
                        className="pointer-events-none absolute inset-0 -z-10 bg-gradient-brand opacity-[0.04]"
                    />
                    <p className="text-sm font-medium">Nothing to show yet</p>
                    <p className="max-w-sm text-xs text-muted-foreground">
                        Once you add clients and schedule posts, activity and upcoming deadlines will
                        appear here.
                    </p>
                </div>
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
