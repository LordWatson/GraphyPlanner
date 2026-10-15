import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Download, FileText } from 'lucide-react';

import { Page, PageHeader } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCurrency } from '@/lib/currency';
import { index as portalInvoicesIndex } from '@/routes/portal/invoices';

type InvoiceData = {
    id: number;
    invoice_number: string;
    status: string;
    status_label: string;
    amount: string;
    currency: string;
    issue_date: string;
    due_date: string | null;
    is_unpaid: boolean;
    pdf_url: string | null;
    pdf_original_filename: string | null;
};

/**
 * Step 6.6 — read-only detail for a single invoice inside the client portal: a document-style
 * summary (amount, dates, status) plus the attached PDF. No create/edit/send/mark-paid actions.
 */
export default function PortalInvoiceShow({
    invoice,
}: {
    invoice: InvoiceData;
}) {
    const { currency } = usePage<{ currency: string }>().props;

    return (
        <>
            <Head title={`Invoice ${invoice.invoice_number} — Client portal`} />
            <Page>
                <Link
                    href={portalInvoicesIndex()}
                    className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" />
                    All invoices
                </Link>

                <PageHeader
                    eyebrow="Invoice"
                    title={
                        <span className="font-mono text-[0.8em] tracking-[-0.04em]">
                            {invoice.invoice_number}
                        </span>
                    }
                    meta={
                        <Badge
                            variant={invoice.is_unpaid ? 'warning' : 'success'}
                        >
                            <span
                                aria-hidden
                                className={
                                    invoice.is_unpaid
                                        ? 'bg-warning size-1.5 rounded-full'
                                        : 'bg-success size-1.5 rounded-full'
                                }
                            />
                            {invoice.is_unpaid
                                ? 'Unpaid'
                                : invoice.status_label}
                        </Badge>
                    }
                    actions={
                        invoice.pdf_url && (
                            <Button variant="outline" asChild>
                                <a
                                    href={invoice.pdf_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    download={
                                        invoice.pdf_original_filename ??
                                        undefined
                                    }
                                >
                                    <Download />
                                    Download PDF
                                </a>
                            </Button>
                        )
                    }
                />

                <section className="animate-rise border-border bg-card grid grid-cols-1 overflow-hidden rounded-2xl border sm:grid-cols-[1.2fr_1fr_1fr]">
                    <div
                        className={
                            invoice.is_unpaid
                                ? 'bg-ink text-ink-foreground flex flex-col gap-2 p-6'
                                : 'flex flex-col gap-2 p-6'
                        }
                    >
                        <span className="eyebrow opacity-70">
                            {invoice.is_unpaid ? 'Amount due' : 'Amount'}
                        </span>
                        <span className="font-serif text-5xl leading-none tabular-nums">
                            {formatCurrency(invoice.amount, currency)}
                        </span>
                    </div>
                    <div className="border-border flex flex-col gap-2 border-t p-6 sm:border-t-0 sm:border-l">
                        <span className="eyebrow text-muted-foreground">
                            Issued
                        </span>
                        <span className="font-mono text-sm tabular-nums">
                            {invoice.issue_date}
                        </span>
                    </div>
                    <div className="border-border flex flex-col gap-2 border-t p-6 sm:border-t-0 sm:border-l">
                        <span className="eyebrow text-muted-foreground">
                            Due
                        </span>
                        <span className="font-mono text-sm tabular-nums">
                            {invoice.due_date ?? 'On receipt'}
                        </span>
                    </div>
                </section>

                {invoice.pdf_url ? (
                    <section className="flex flex-col gap-3">
                        <span className="eyebrow text-muted-foreground flex items-center gap-2">
                            <FileText className="size-3.5" />
                            {invoice.pdf_original_filename ?? 'invoice.pdf'}
                        </span>
                        <iframe
                            src={invoice.pdf_url}
                            title={`Invoice ${invoice.invoice_number} PDF`}
                            className="border-border bg-card shadow-paper h-[75vh] w-full rounded-2xl border"
                        />
                    </section>
                ) : (
                    <p className="text-muted-foreground text-[13px]">
                        No PDF has been attached to this invoice yet.
                    </p>
                )}
            </Page>
        </>
    );
}
