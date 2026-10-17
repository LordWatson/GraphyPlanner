import { Head, Link } from '@inertiajs/react';
import { Download, Pencil } from 'lucide-react';
import { Page, PageHeader } from '@/components/page';
import { PlatformAnalyticsView } from '@/components/super-admin/platform-analytics-view';
import type {
    PlatformSummary,
    ResolvedFilters,
} from '@/components/super-admin/types';
import { describeRange } from '@/components/super-admin/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateRange } from '@/lib/dates';
import {
    edit as editReport,
    exportMethod as exportReport,
    index as reportsIndex,
} from '@/routes/super-admin/reports';

type Report = {
    id: number;
    name: string;
    description: string | null;
    filters: ResolvedFilters;
    creator: string | null;
    last_run_at: string | null;
};

/**
 * Step 15.5 — a saved report, re-run against today's data with the analytics page's own view,
 * plus a CSV export of the same run.
 */
export default function ShowReport({
    report,
    summary,
}: {
    report: Report;
    summary: PlatformSummary;
}) {
    return (
        <>
            <Head title={report.name} />
            <Page>
                <PageHeader
                    eyebrow={formatDateRange(
                        summary.filters.from,
                        summary.filters.to,
                    )}
                    title={report.name}
                    description={
                        report.description ??
                        'A saved analytics view, freshly run against today’s data.'
                    }
                    meta={
                        <>
                            <Badge variant="electric">
                                {describeRange(report.filters)}
                            </Badge>
                            {report.creator && (
                                <span className="text-muted-foreground font-mono text-xs">
                                    Saved by {report.creator}
                                </span>
                            )}
                        </>
                    }
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                {/* A file download, not an Inertia visit. */}
                                <a href={exportReport.url(report.id)}>
                                    <Download />
                                    Export CSV
                                </a>
                            </Button>
                            <Button asChild>
                                <Link href={editReport(report.id)}>
                                    <Pencil />
                                    Edit report
                                </Link>
                            </Button>
                        </>
                    }
                />

                <PlatformAnalyticsView
                    summary={summary}
                    scope={
                        summary.filters.org_ids.length === 1
                            ? 'organization'
                            : 'platform'
                    }
                />
            </Page>
        </>
    );
}

ShowReport.layout = {
    breadcrumbs: [{ title: 'Reports', href: reportsIndex() }],
};
