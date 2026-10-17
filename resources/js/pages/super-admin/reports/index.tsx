import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, FileBarChart, Plus } from 'lucide-react';
import { EmptyState, Page, PageHeader } from '@/components/page';
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

/**
 * Step 15.5 — saved report configurations. Each row reads like a spec line: the range, the
 * organizations and the metric sections it covers; opening one re-runs it against today's data.
 */
export default function ReportsIndex({ reports }: { reports: Report[] }) {
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
                    description="Analytics views you’ve named and kept. Relative ranges re-run against today every time you open one."
                    actions={
                        <Button asChild>
                            <Link href={createReport()}>
                                <Plus />
                                Build a report
                            </Link>
                        </Button>
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
                                                {report.organization_names
                                                    .length === 0
                                                    ? 'All organizations'
                                                    : report.organization_names
                                                            .length === 1
                                                      ? report
                                                            .organization_names[0]
                                                      : `${report.organization_names.length} organizations`}
                                            </Badge>
                                            {(report.filters.metrics.length ===
                                            0
                                                ? ['All metrics']
                                                : report.filters.metrics.map(
                                                      (metric) =>
                                                          metricLabels[metric],
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
    breadcrumbs: [{ title: 'Reports', href: reportsIndex() }],
};
