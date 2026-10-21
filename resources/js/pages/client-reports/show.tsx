import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Download,
    MessageSquareQuote,
    RefreshCw,
    Send,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { ClientReportDocument } from '@/components/client-reports/client-report-document';
import type { ClientReportDocumentData } from '@/components/client-reports/client-report-document';
import { confirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Page, PageHeader, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/dates';
import { show as showCampaign } from '@/routes/campaigns';
import {
    pdf as reportPdf,
    publish as publishReport,
    refresh as refreshReport,
    update as updateReport,
} from '@/routes/client-reports';
import { index as clientsIndex } from '@/routes/clients';
import { index as clientReports } from '@/routes/clients/reports';

type Props = {
    client: { id: number; name: string };
    report: ClientReportDocumentData;
    recipients: { name: string | null; email: string }[];
    can: { update: boolean; publish: boolean };
};

/**
 * Step M-3.3 — one monthly report in the studio: the commentary editor and publish controls
 * (drafts), then the report exactly as the client will see it. Publishing freezes the figures and
 * emails the client's contacts.
 */
export default function ClientReportShow({
    client,
    report,
    recipients,
    can,
}: Props) {
    const form = useForm({ commentary: report.commentary ?? '' });
    const [busy, setBusy] = useState(false);
    const published = report.status === 'published';

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put(updateReport.url(report.id), { preserveScroll: true });
    };

    const refresh = () =>
        router.post(
            refreshReport.url(report.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );

    const publish = async () => {
        if (
            !(await confirmDialog({
                title: `Publish the ${report.period_label} report?`,
                description:
                    recipients.length > 0
                        ? `It appears in ${client.name}’s portal and is emailed to ${recipients.length} ${recipients.length === 1 ? 'contact' : 'contacts'}. Its figures are frozen from then on.`
                        : `It appears in ${client.name}’s portal. Nobody is emailed — the client has no contacts yet. Its figures are frozen from then on.`,
                confirmLabel: 'Publish report',
            }))
        ) {
            return;
        }

        const send = () =>
            router.post(
                publishReport.url(report.id),
                {},
                {
                    preserveScroll: true,
                    onStart: () => setBusy(true),
                    onFinish: () => setBusy(false),
                },
            );

        // Unsaved commentary goes out with the report: save it first, then publish.
        if (form.isDirty) {
            form.put(updateReport.url(report.id), {
                preserveScroll: true,
                onSuccess: send,
            });

            return;
        }

        send();
    };

    return (
        <>
            <Head title={`${client.name} · ${report.period_label} report`} />
            <Page>
                <PageHeader
                    eyebrow={`Monthly report — ${client.name}`}
                    title={
                        <>
                            {report.period_label}, in <em>social</em>.
                        </>
                    }
                    description={
                        published
                            ? `Published ${formatDateTime(report.published_at)}${report.recipients_count > 0 ? ` and emailed to ${report.recipients_count} ${report.recipients_count === 1 ? 'contact' : 'contacts'}` : ''}. Its figures are frozen.`
                            : `Draft — only your team can see it. Figures as of ${formatDateTime(report.snapshot_at)}.`
                    }
                    meta={
                        <StatusPill
                            kind="clientReport"
                            value={report.status}
                            label={report.status_label}
                        />
                    }
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={clientReports(client.id)}>
                                    <ArrowLeft />
                                    All reports
                                </Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <a
                                    href={reportPdf.url(report.id)}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Download />
                                    PDF
                                </a>
                            </Button>
                            {can.update && (
                                <Button
                                    variant="outline"
                                    onClick={refresh}
                                    disabled={busy}
                                >
                                    <RefreshCw />
                                    Refresh figures
                                </Button>
                            )}
                            {can.publish && (
                                <Button onClick={publish} disabled={busy}>
                                    <Send />
                                    Publish
                                </Button>
                            )}
                        </>
                    }
                />

                {can.update && (
                    <Panel
                        eyebrow="Your note to the client"
                        title="Commentary"
                        icon={MessageSquareQuote}
                        description="What worked, what you’ll try next. It opens the report, above the numbers."
                    >
                        <form onSubmit={save} className="grid gap-3">
                            <Textarea
                                aria-label="Commentary"
                                rows={5}
                                maxLength={5000}
                                value={form.data.commentary}
                                onChange={(event) =>
                                    form.setData(
                                        'commentary',
                                        event.target.value,
                                    )
                                }
                                placeholder="A strong month for reels: the menu launch reached twice our usual audience…"
                            />
                            <InputError message={form.errors.commentary} />
                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={form.processing || !form.isDirty}
                                >
                                    Save commentary
                                </Button>
                            </div>
                        </form>
                    </Panel>
                )}

                <ClientReportDocument
                    report={{
                        ...report,
                        commentary: can.update
                            ? form.data.commentary || null
                            : report.commentary,
                    }}
                    campaignHref={(id) => showCampaign(id)}
                    postLinks
                />
            </Page>
        </>
    );
}

ClientReportShow.layout = {
    breadcrumbs: [{ title: 'Clients', href: clientsIndex() }],
};
