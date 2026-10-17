import { Head, Link } from '@inertiajs/react';
import { Download, FileBarChart, FileText, Mail, Pencil } from 'lucide-react';
import { describeRange } from '@/components/analytics/format';
import { OrganizationAnalyticsView } from '@/components/analytics/organization-analytics-view';
import type {
    AnalyticsAbilities,
    OrgFilters,
    OrgSummary,
} from '@/components/analytics/types';
import { Page, PageHeader } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDateRange, formatDateTime } from '@/lib/dates';
import { index as analyticsIndex } from '@/routes/analytics';
import {
    edit as editReport,
    exportMethod as exportReport,
    index as reportsIndex,
    pdf as reportPdf,
} from '@/routes/analytics/reports';

type Report = {
    id: number;
    name: string;
    description: string | null;
    filters: OrgFilters;
    creator: string | null;
    schedule_label: string | null;
    recipient_count: number;
    last_sent_at: string | null;
    can: { update: boolean; delete: boolean };
};

/**
 * Step 17.6 — a saved report, re-run against today's data for whoever opens it, with CSV and
 * branded PDF exports of the same run (Step 17.7).
 */
export default function ShowReport({
    report,
    summary,
}: {
    report: Report;
    summary: OrgSummary;
    can: AnalyticsAbilities;
}) {
    return (
        <>
            <Head title={report.name} />
            <Page>
                <PageHeader
                    eyebrow={`${formatDateRange(summary.filters.from, summary.filters.to)} · ${summary.filters.timezone}`}
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
                            {summary.client && (
                                <Badge variant="lilac">
                                    {summary.client.name}
                                </Badge>
                            )}
                            {report.schedule_label && (
                                <span className="text-muted-foreground inline-flex items-center gap-1.5 font-mono text-xs">
                                    <Mail className="size-3" />
                                    {report.schedule_label} to{' '}
                                    {report.recipient_count}
                                    {report.last_sent_at &&
                                        ` · last sent ${formatDateTime(report.last_sent_at)}`}
                                </span>
                            )}
                            {report.creator && (
                                <span className="text-muted-foreground font-mono text-xs">
                                    Saved by {report.creator}
                                </span>
                            )}
                        </>
                    }
                    actions={
                        <>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="outline">
                                        <Download />
                                        Export
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {/* File downloads, not Inertia visits. */}
                                    <DropdownMenuItem asChild>
                                        <a href={exportReport.url(report.id)}>
                                            <FileBarChart />
                                            CSV spreadsheet
                                        </a>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem asChild>
                                        <a href={reportPdf.url(report.id)}>
                                            <FileText />
                                            Branded PDF
                                        </a>
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                            {report.can.update && (
                                <Button asChild>
                                    <Link href={editReport(report.id)}>
                                        <Pencil />
                                        Edit report
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <OrganizationAnalyticsView summary={summary} />
            </Page>
        </>
    );
}

ShowReport.layout = {
    breadcrumbs: [
        { title: 'Analytics', href: analyticsIndex() },
        { title: 'Reports', href: reportsIndex() },
    ],
};
