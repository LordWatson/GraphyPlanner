import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    Building2,
    FileBarChart,
    PauseCircle,
    Plus,
} from 'lucide-react';
import { ChapterHero, EmptyState, Page, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { PlatformAnalyticsView } from '@/components/super-admin/platform-analytics-view';
import type { PlatformSummary } from '@/components/super-admin/types';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/lib/dates';
import { dashboard } from '@/routes/super-admin';
import { index as analyticsIndex } from '@/routes/super-admin/analytics';
import {
    create as createOrganization,
    index as organizationsIndex,
    show as showOrganization,
} from '@/routes/super-admin/organizations';
import {
    create as createReport,
    index as reportsIndex,
    show as showReport,
} from '@/routes/super-admin/reports';
import type { Auth } from '@/types';

type OrganizationRow = {
    id: number;
    name: string;
    owner: string | null;
    owner_email: string | null;
    created_at: string | null;
    suspended_at: string | null;
    suspension_reason: string | null;
    users_count: number;
};

type SchedulerRow = {
    command: string;
    label: string;
    interval_minutes: number;
    last_ran_at: string | null;
    stale: boolean;
};

type Props = {
    summary: PlatformSummary;
    recentOrganizations: OrganizationRow[];
    suspendedOrganizations: OrganizationRow[];
    reports: { id: number; name: string; last_run_at: string | null }[];
    health: {
        schedulers: SchedulerRow[];
        queue: { pending: number | null; failed: number };
    };
};

/**
 * Step I-1.8 — scheduler heartbeats (stale when a command hasn't run within two of its intervals)
 * and the queue backlog, so a stopped cron or worker is visible before customers notice.
 */
function SystemHealthPanel({ health }: { health: Props['health'] }) {
    const staleCount = health.schedulers.filter((row) => row.stale).length;

    return (
        <Panel
            eyebrow={
                staleCount === 0
                    ? 'All schedulers running'
                    : `${staleCount} stale`
            }
            title="System health"
            icon={Activity}
            bodyClassName="p-0 sm:p-0"
        >
            <ul className="divide-border/70 divide-y">
                {health.schedulers.map((row) => (
                    <li
                        key={row.command}
                        className="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3"
                    >
                        <span className="min-w-0 flex-1">
                            <span className="block text-sm font-medium">
                                {row.label}
                            </span>
                            <span className="text-muted-foreground font-mono text-xs">
                                {row.last_ran_at
                                    ? `Last ran ${formatDateTime(row.last_ran_at)}`
                                    : 'No run recorded yet'}
                            </span>
                        </span>
                        <StatusPill
                            kind="scheduler"
                            value={row.stale ? 'stale' : 'ok'}
                        />
                    </li>
                ))}
                <li className="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                    <span className="min-w-0 flex-1 text-sm font-medium">
                        Queue
                    </span>
                    <span className="text-muted-foreground font-mono text-xs tabular-nums">
                        {health.queue.pending === null
                            ? 'pending: n/a'
                            : `${health.queue.pending} pending`}{' '}
                        · {health.queue.failed} failed
                    </span>
                </li>
            </ul>
        </Panel>
    );
}

function greeting(): string {
    const hour = new Date().getHours();

    if (hour < 12) {
        return 'Good morning';
    }

    return hour < 18 ? 'Good afternoon' : 'Good evening';
}

/**
 * Step 15.1 — where a super admin lands after login: the platform's last 30 days, then the lists
 * support works from (newest signups, suspended workspaces, saved reports).
 */
export default function SuperAdminDashboard({
    summary,
    recentOrganizations,
    suspendedOrganizations,
    reports,
    health,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const firstName = auth.user.name.split(' ')[0];
    const organizations = summary.organizations;

    return (
        <>
            <Head title="Platform overview" />
            <Page>
                <ChapterHero
                    eyebrow="Platform — last 30 days"
                    title={
                        <>
                            {greeting()}, <em>{firstName}</em>.
                        </>
                    }
                    description="Every organization on Alima in one place — who's new, who's busy, and who needs a look."
                    actions={
                        <>
                            <Button variant="spark" size="lg" asChild>
                                <Link href={createOrganization()}>
                                    <Plus />
                                    Onboard an organization
                                </Link>
                            </Button>
                            <Button variant="on-ink" size="lg" asChild>
                                <Link href={analyticsIndex()}>
                                    See analytics
                                </Link>
                            </Button>
                        </>
                    }
                    aside={
                        organizations && (
                            <div className="flex flex-col items-start gap-1 lg:items-end">
                                <span className="eyebrow text-cream/60">
                                    Organizations
                                </span>
                                <span className="font-display text-lime text-[5.5rem] leading-none font-bold tracking-[-0.04em] tabular-nums">
                                    {organizations.total}
                                </span>
                                <span className="text-cream/70 text-sm">
                                    {organizations.active} active ·{' '}
                                    {organizations.suspended} suspended
                                </span>
                            </div>
                        )
                    }
                />

                <PlatformAnalyticsView summary={summary} />

                <SystemHealthPanel health={health} />

                <div className="grid gap-5 lg:grid-cols-[1.45fr_1fr]">
                    <Panel
                        eyebrow="Newest first"
                        title="Recent signups"
                        icon={Building2}
                        actions={
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={organizationsIndex()}>
                                    All organizations
                                    <ArrowRight />
                                </Link>
                            </Button>
                        }
                        bodyClassName="p-0 sm:p-0"
                    >
                        {recentOrganizations.length === 0 ? (
                            <div className="p-5 sm:p-6">
                                <EmptyState
                                    compact
                                    icon={Building2}
                                    title="No organizations yet."
                                    description="Share the signup link, or onboard the first one yourself."
                                    action={
                                        <Button size="sm" asChild>
                                            <Link href={createOrganization()}>
                                                <Plus />
                                                Onboard an organization
                                            </Link>
                                        </Button>
                                    }
                                />
                            </div>
                        ) : (
                            <ul className="divide-border/70 divide-y">
                                {recentOrganizations.map((organization) => (
                                    <li key={organization.id}>
                                        <Link
                                            href={showOrganization(
                                                organization.id,
                                            )}
                                            className="hover:bg-muted/50 flex items-center gap-4 px-5 py-3.5 transition-colors sm:px-6"
                                        >
                                            <span className="bg-accent text-accent-foreground font-display flex size-10 shrink-0 items-center justify-center rounded-full">
                                                {organization.name
                                                    .charAt(0)
                                                    .toUpperCase()}
                                            </span>
                                            <span className="flex min-w-0 flex-1 flex-col">
                                                <span className="truncate font-medium">
                                                    {organization.name}
                                                </span>
                                                <span className="text-muted-foreground truncate text-xs">
                                                    {organization.owner
                                                        ? `${organization.owner} · ${organization.owner_email}`
                                                        : 'No owner yet'}
                                                </span>
                                            </span>
                                            {organization.suspended_at && (
                                                <StatusPill
                                                    kind="organization"
                                                    value="suspended"
                                                />
                                            )}
                                            <span className="text-muted-foreground hidden font-mono text-xs sm:inline">
                                                {formatDate(
                                                    organization.created_at,
                                                )}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <div className="flex flex-col gap-5">
                        <Panel
                            eyebrow={`${suspendedOrganizations.length} on hold`}
                            title="Suspended"
                            icon={PauseCircle}
                        >
                            {suspendedOrganizations.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    No workspace is suspended. All clear.
                                </p>
                            ) : (
                                <ul className="flex flex-col gap-3">
                                    {suspendedOrganizations.map(
                                        (organization) => (
                                            <li key={organization.id}>
                                                <Link
                                                    href={showOrganization(
                                                        organization.id,
                                                    )}
                                                    className="group flex flex-col gap-0.5"
                                                >
                                                    <span className="font-medium group-hover:underline">
                                                        {organization.name}
                                                    </span>
                                                    <span className="text-muted-foreground line-clamp-1 text-xs">
                                                        Since{' '}
                                                        {formatDate(
                                                            organization.suspended_at,
                                                        )}
                                                        {organization.suspension_reason
                                                            ? ` — ${organization.suspension_reason}`
                                                            : ''}
                                                    </span>
                                                </Link>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            )}
                        </Panel>

                        <Panel
                            eyebrow="Saved views"
                            title="Reports"
                            icon={FileBarChart}
                            actions={
                                <Button variant="ghost" size="sm" asChild>
                                    <Link
                                        href={
                                            reports.length
                                                ? reportsIndex()
                                                : createReport()
                                        }
                                    >
                                        {reports.length ? 'All' : 'Build one'}
                                        <ArrowRight />
                                    </Link>
                                </Button>
                            }
                        >
                            {reports.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    Save an analytics view to re-run it in one
                                    click.
                                </p>
                            ) : (
                                <ul className="flex flex-col gap-2.5">
                                    {reports.map((report) => (
                                        <li
                                            key={report.id}
                                            className="flex items-baseline justify-between gap-3"
                                        >
                                            <Link
                                                href={showReport(report.id)}
                                                className="truncate font-medium hover:underline"
                                            >
                                                {report.name}
                                            </Link>
                                            <span className="text-muted-foreground shrink-0 font-mono text-[11px]">
                                                {report.last_run_at
                                                    ? formatDateTime(
                                                          report.last_run_at,
                                                      )
                                                    : 'Never run'}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    </div>
                </div>
            </Page>
        </>
    );
}

SuperAdminDashboard.layout = {
    breadcrumbs: [{ title: 'Platform', href: dashboard() }],
};
