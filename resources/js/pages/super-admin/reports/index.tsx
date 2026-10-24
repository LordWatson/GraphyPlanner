import { Head, Link } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { FileBarChart, Plus } from 'lucide-react';
import { DataTable } from '@/components/data-table';
import { ChapterHero, EmptyState, HeroStat, Page } from '@/components/page';
import type { ResolvedFilters } from '@/components/super-admin/types';
import { describeRange, metricLabels } from '@/components/super-admin/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/dates';
import {
    create as createReport,
    index as reportsIndex,
    show as showReport,
} from '@/routes/super-admin/reports';

type Report = {
    id: number;
    name: string;
    description: string | null;
    filters: ResolvedFilters;
    creator: string | null;
    last_run_at: string | null;
    updated_at: string | null;
    organization_names: string[];
};

/** Step M-1.4: saved reports on the shared `DataTable` (short list: sorted in place). */
const reportColumns: ColumnDef<Report, unknown>[] = [
    {
        id: 'name',
        accessorKey: 'name',
        header: 'Report',
        enableSorting: true,
        cell: ({ row }) => {
            const report = row.original;
            const orgs = report.organization_names;

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
                            {orgs.length === 0
                                ? 'All organizations'
                                : orgs.length === 1
                                  ? orgs[0]
                                  : `${orgs.length} organizations`}
                        </Badge>
                        {(report.filters.metrics.length === 0
                            ? ['All metrics']
                            : report.filters.metrics.map(
                                  (metric) => metricLabels[metric],
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
        id: 'last_run_at',
        accessorFn: (report) => report.last_run_at ?? '',
        header: 'Last run',
        enableSorting: true,
        meta: {
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
 * Step 15.5 — saved report configurations. Each row reads like a spec line: the range, the
 * organizations and the metric sections it covers; opening one re-runs it against today's data.
 */
export default function ReportsIndex({ reports }: { reports: Report[] }) {
    return (
        <>
            <Head title="Reports" />
            <Page>
                <ChapterHero
                    eyebrow={`${reports.length} saved`}
                    title={
                        <>
                            Saved <em>reports</em>.
                        </>
                    }
                    description="Analytics views you’ve named and kept. Relative ranges re-run against today every time you open one."
                    actions={
                        <Button variant="spark" size="lg" asChild>
                            <Link href={createReport()}>
                                <Plus />
                                Build a report
                            </Link>
                        </Button>
                    }
                    aside={
                        <HeroStat
                            icon={FileBarChart}
                            eyebrow="Saved"
                            value={String(reports.length).padStart(2, '0')}
                            caption="Analytics views kept"
                        />
                    }
                />

                {reports.length === 0 ? (
                    <EmptyState
                        icon={FileBarChart}
                        title="No reports yet."
                        description="Pick a range, organizations and metrics once, then re-run or export it in one click."
                        action={
                            <Button asChild>
                                <Link href={createReport()}>
                                    <Plus />
                                    Build a report
                                </Link>
                            </Button>
                        }
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
                    />
                )}
            </Page>
        </>
    );
}

ReportsIndex.layout = {
    breadcrumbs: [{ title: 'Reports', href: reportsIndex() }],
};
