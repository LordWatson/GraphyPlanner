import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Download } from 'lucide-react';
import { ClientReportDocument } from '@/components/client-reports/client-report-document';
import type { ClientReportDocumentData } from '@/components/client-reports/client-report-document';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/dates';
import { show as portalCampaign } from '@/routes/portal/campaigns';
import { index as portalPosts } from '@/routes/portal/posts';
import {
    index as portalReports,
    pdf as reportPdf,
} from '@/routes/portal/reports';

/**
 * Step M-3.3 — one published monthly report in the client portal, with its PDF. Mobile first.
 */
export default function PortalReportShow({
    report,
}: {
    report: ClientReportDocumentData;
}) {
    const studio = report.snapshot?.studio.name;

    return (
        <>
            <Head title={`${report.period_label} report — Client portal`} />
            <Page>
                <Link
                    href={portalReports()}
                    className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" />
                    All reports
                </Link>

                <PageHeader
                    eyebrow={`Monthly report — shared ${formatDate(report.published_at)}`}
                    title={
                        <>
                            {report.period_label}, in <em>social</em>.
                        </>
                    }
                    description={
                        studio ? `Prepared for you by ${studio}.` : undefined
                    }
                    actions={
                        <Button asChild variant="outline">
                            <a
                                href={reportPdf.url(report.id)}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <Download />
                                Download PDF
                            </a>
                        </Button>
                    }
                />

                <ClientReportDocument
                    report={report}
                    campaignHref={(id) => portalCampaign(id)}
                    waitingHref={portalPosts()}
                />
            </Page>
        </>
    );
}
