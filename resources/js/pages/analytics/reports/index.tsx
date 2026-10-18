import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, FileBarChart, Mail, Plus } from 'lucide-react';
import { describeRange } from '@/components/analytics/format';
import type { OrgFilters } from '@/components/analytics/types';
import { sectionLabels } from '@/components/analytics/types';
import { EmptyState, Page, PageHeader } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/dates';
import { index as analyticsIndex } from '@/routes/analytics';
import {
    create as createReport,
    index as reportsIndex,
    show as showReport,
} from '@/routes/analytics/reports';

type Report = {
    id: number;
    name: string;
    description: string | null;
    filters: OrgFilters;
    creator: string | null;
    schedule: 'weekly' | 'monthly' | null;
    schedule_label: string | null;
    recipient_count: number;
    last_run_at: string | null;
    last_sent_at: string | null;
    client_names: string[];
};

/**
 * Step 17.6 — the studio's saved reports, shared with the whole team. Each row reads like a spec
 * line: range, clients, sections and (Step 17.8) its email schedule. Opening one re-runs it for
 * whoever opens it.
 */
export default function ReportsIndex({
    reports,
    can,
}: {
    reports: Report[];
    can: { create: boolean };
}) {
    const build = can.create && (
        <Button asChild>
            <Link href={createReport()}>
                <Plus />
                Build a report
            </Link>
        </Button>
    );

    return (
        <>
            <Head title="Reports" />
            <Page>
                <PageHeader
                    eyebrow={`${reports.length} saved`}
                    title={
                        <>
                            Saved <em>reports</em>.
                        </>
                    }
                    description="Analytics views the team has named and kept. Relative ranges re-run against today; everyone sees only what their role allows."
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={analyticsIndex()}>
                                    Live analytics
                                </Link>
                            </Button>
                            {build}
                        </>
                    }
                />

                {reports.length === 0 ? (
                    <EmptyState
                        icon={FileBarChart}
                        title="No reports yet."
                        description={
                            can.create
                                ? 'Pick a range, clients and sections once, then re-run, export or email it whenever you need it.'
                                : 'When an Owner or Strategist saves a report, it shows up here for the whole team.'
                        }
                        action={build || undefined}
                    />
                ) : (
                    <ul className="border-foreground/85 flex flex-col border-t">
                        {reports.map((report, index) => (
                            <li
                                key={report.id}
                                className="border-border border-b"
                            >
                                <Link
                                    href={showReport(report.id)}
                                    className="group hover:bg-muted/50 grid gap-3 px-1 py-5 transition-colors sm:grid-cols-[3rem_minmax(0,1fr)_auto] sm:items-start sm:px-3"
                                >
                                    <span className="text-muted-foreground font-mono text-[11px] tabular-nums sm:pt-1">
                                        {String(index + 1).padStart(2, '0')}
                                    </span>
                                    <span className="flex min-w-0 flex-col gap-2">
                                        <span className="flex items-center gap-2 text-[17px] font-semibold tracking-[-0.01em] group-hover:underline">
                                            {report.name}
                                            <ArrowUpRight className="text-muted-foreground size-4 opacity-0 transition-opacity group-hover:opacity-100" />
                                        </span>
                                        {report.description && (
                                            <span className="text-muted-foreground text-sm text-pretty">
                                                {report.description}
                                            </span>
                                        )}
                                        <span className="flex flex-wrap gap-1.5">
                                            <Badge variant="electric">
                                                {describeRange(report.filters)}
                                            </Badge>
                                            <Badge variant="lilac">
                                                {report.client_names.length ===
                                                0
                                                    ? 'All clients'
                                                    : report.client_names
                                                            .length === 1
                                                      ? report.client_names[0]
                                                      : `${report.client_names.length} clients`}
                                            </Badge>
                                            {(report.filters.metrics.length ===
                                            0
                                                ? ['All sections']
                                                : report.filters.metrics.map(
                                                      (metric) =>
                                                          sectionLabels[metric],
                                                  )
                                            ).map((label) => (
                                                <Badge
                                                    key={label}
                                                    variant="lime"
                                                >
                                                    {label}
                                                </Badge>
                                            ))}
                                        </span>
                                    </span>
                                    <span className="text-muted-foreground flex flex-col gap-0.5 font-mono text-[11px] sm:items-end sm:text-right">
                                        {report.schedule_label && (
                                            <span className="text-foreground inline-flex items-center gap-1.5">
                                                <Mail className="size-3" />
                                                {report.schedule_label} ·{' '}
                                                {report.recipient_count}{' '}
                                                {report.recipient_count === 1
                                                    ? 'person'
                                                    : 'people'}
                                            </span>
                                        )}
                                        <span>
                                            {report.last_run_at
                                                ? `Run ${formatDateTime(report.last_run_at)}`
                                                : 'Never run'}
                                        </span>
                                        {report.creator && (
                                            <span>By {report.creator}</span>
                                        )}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
        </>
    );
}

ReportsIndex.layout = {
    breadcrumbs: [
        { title: 'Analytics', href: analyticsIndex() },
        { title: 'Reports', href: reportsIndex() },
    ],
};
