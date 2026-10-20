import { Head, Link } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { FileBarChart, Mail, Plus } from 'lucide-react';
import { describeRange } from '@/components/analytics/format';
import type { OrgFilters } from '@/components/analytics/types';
import { sectionLabels } from '@/components/analytics/types';
import { DataTable } from '@/components/data-table';
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

/** Step M-1.4: saved reports on the shared `DataTable` (short list: sorted in place). */
const reportColumns: ColumnDef<Report, unknown>[] = [
    {
        id: 'name',
        accessorKey: 'name',
        header: 'Report',
        enableSorting: true,
        enableHiding: false,
        cell: ({ row }) => {
            const report = row.original;

            return (
                <span className="flex min-w-0 flex-col gap-2 py-1">
                    <Link
                        href={showReport(report.id)}
                        className="text-[17px] font-semibold tracking-[-0.01em] hover:underline"
                    >
                        {report.name}
                    </Link>
                    {report.description && (
                        <span className="text-muted-foreground text-sm text-pretty whitespace-normal">
                            {report.description}
                        </span>
                    )}
                    <span className="flex flex-wrap gap-1.5">
                        <Badge variant="electric">
                            {describeRange(report.filters)}
                        </Badge>
                        <Badge variant="lilac">
                            {report.client_names.length === 0
                                ? 'All clients'
                                : report.client_names.length === 1
                                  ? report.client_names[0]
                                  : `${report.client_names.length} clients`}
                        </Badge>
                        {(report.filters.metrics.length === 0
                            ? ['All sections']
                            : report.filters.metrics.map(
                                  (metric) => sectionLabels[metric],
                              )
                        ).map((label) => (
                            <Badge key={label} variant="lime">
                                {label}
                            </Badge>
                        ))}
                    </span>
                </span>
            );
        },
    },
    {
        id: 'schedule',
        accessorFn: (report) => report.schedule_label ?? '',
        header: 'Schedule',
        enableSorting: true,
        meta: {
            label: 'Schedule',
            hideBelow: 'md',
            className: 'font-mono text-[11px]',
        },
        cell: ({ row }) =>
            row.original.schedule_label ? (
                <span className="inline-flex items-center gap-1.5">
                    <Mail className="size-3" />
                    {row.original.schedule_label} ·{' '}
                    {row.original.recipient_count}{' '}
                    {row.original.recipient_count === 1 ? 'person' : 'people'}
                </span>
            ) : (
                <span className="text-muted-foreground">Not emailed</span>
            ),
    },
    {
        id: 'last_run_at',
        accessorFn: (report) => report.last_run_at ?? '',
        header: 'Last run',
        enableSorting: true,
        meta: {
            label: 'Last run',
            hideBelow: 'sm',
            className: 'text-muted-foreground font-mono text-[11px]',
        },
        cell: ({ row }) => (
            <>
                {row.original.last_run_at
                    ? formatDateTime(row.original.last_run_at)
                    : 'Never run'}
                {row.original.creator && (
                    <span className="block">By {row.original.creator}</span>
                )}
            </>
        ),
    },
];

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
                    <DataTable
                        caption="Saved reports"
                        columns={reportColumns}
                        data={reports}
                        getRowId={(report) => String(report.id)}
                        rowHref={(report) => showReport.url(report.id)}
                        pageSize={25}
                        paginationLabel="reports"
                        storageKey="analytics-reports"
                    />
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
