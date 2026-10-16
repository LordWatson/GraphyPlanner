import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Download, FileText, Landmark } from 'lucide-react';

import { formatInvoiceDate } from '@/components/invoices/types';
import { Page, PageHeader, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCurrency } from '@/lib/currency';
import { cn } from '@/lib/utils';
import { index as portalInvoicesIndex } from '@/routes/portal/invoices';

type InvoiceItem = {
    id: number;
    description: string;
    is_billable: boolean;
    price: string | null;
    quantity: number;
    line_total: number;
};

type InvoiceData = {
    id: number;
    invoice_number: string;
    status: string;
    status_label: string;
    display_status: string;
    display_status_label: string;
    amount: string;
    currency: string;
    issue_date: string;
    due_date: string | null;
    paid_at: string | null;
    description: string | null;
    is_unpaid: boolean;
    is_overdue: boolean;
    days_overdue: number;
    pdf_url: string | null;
    pdf_original_filename: string | null;
    items: InvoiceItem[];
    items_subtotal: number;
    retainer_amount_snapshot: string | null;
};

const headlines: Record<string, [string, string]> = {
    sent: ['Ready for', 'payment'],
    overdue: ['Payment', 'overdue'],
    paid: ['Paid —', 'thank you'],
    void: ['No longer', 'due'],
};

/**
 * Step 6.6 — read-only detail for a single invoice inside the client portal: a document-style
 * summary (amount, dates, status), the itemised breakdown, how to pay, and the attached PDF.
 * No create/edit/send/mark-paid actions.
 */
export default function PortalInvoiceShow({
    invoice,
    studio,
}: {
    invoice: InvoiceData;
    studio: { name: string; payment_instructions: string | null };
}) {
    // Every figure on the invoice is in the currency it's billed in.
    const currency = invoice.currency;
    const [lead, accent] = headlines[invoice.display_status] ?? [
        'Your',
        'invoice',
    ];
    const retainer = Number(invoice.retainer_amount_snapshot ?? 0);

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
                    eyebrow={
                        <>
                            <span className="font-mono">
                                {invoice.invoice_number}
                            </span>
                            <span aria-hidden>—</span>
                            from {studio.name}
                        </>
                    }
                    title={
                        <>
                            {lead} <em>{accent}</em>
                        </>
                    }
                    meta={
                        invoice.is_unpaid && !invoice.is_overdue ? (
                            <Badge variant="warning">
                                <span
                                    aria-hidden
                                    className="bg-warning size-1.5 rounded-full"
                                />
                                Unpaid
                            </Badge>
                        ) : (
                            <StatusPill
                                kind="invoice"
                                value={invoice.display_status}
                                label={invoice.display_status_label}
                            />
                        )
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
                        className={cn(
                            'flex flex-col gap-2 p-6',
                            invoice.is_unpaid && 'bg-ink text-ink-foreground',
                        )}
                    >
                        <span className="eyebrow opacity-70">
                            {invoice.is_unpaid
                                ? 'Amount due'
                                : invoice.status === 'paid'
                                  ? 'Amount paid'
                                  : 'Amount'}
                        </span>
                        <span
                            className={cn(
                                'font-display text-4xl leading-none font-bold tabular-nums',
                                invoice.status === 'void' &&
                                    'line-through opacity-60',
                            )}
                        >
                            {formatCurrency(invoice.amount, currency)}
                        </span>
                    </div>
                    <div className="border-border flex flex-col gap-2 border-t p-6 sm:border-t-0 sm:border-l">
                        <span className="eyebrow text-muted-foreground">
                            Issued
                        </span>
                        <span className="font-mono text-sm tabular-nums">
                            {formatInvoiceDate(invoice.issue_date)}
                        </span>
                    </div>
                    <div className="border-border flex flex-col gap-2 border-t p-6 sm:border-t-0 sm:border-l">
                        <span className="eyebrow text-muted-foreground">
                            {invoice.paid_at ? 'Paid' : 'Due'}
                        </span>
                        <span
                            className={cn(
                                'font-mono text-sm tabular-nums',
                                invoice.is_overdue && 'text-destructive',
                            )}
                        >
                            {invoice.paid_at
                                ? formatInvoiceDate(invoice.paid_at)
                                : invoice.due_date
                                  ? formatInvoiceDate(invoice.due_date)
                                  : 'On receipt'}
                        </span>
                        {invoice.is_overdue && (
                            <span className="text-destructive text-xs">
                                {invoice.days_overdue} day
                                {invoice.days_overdue === 1 ? '' : 's'} overdue
                            </span>
                        )}
                    </div>
                </section>

                <div className="grid grid-cols-1 gap-5 lg:grid-cols-[1.45fr_1fr]">
                    <Panel
                        eyebrow="Breakdown"
                        title="What this invoice covers"
                        bodyClassName="p-0"
                    >
                        <ul className="divide-border/70 divide-y">
                            {invoice.items.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex items-baseline justify-between gap-4 px-5 py-3"
                                >
                                    <span
                                        className={cn(
                                            'text-sm',
                                            !item.is_billable &&
                                                'text-muted-foreground',
                                        )}
                                    >
                                        {item.description}
                                        {item.quantity > 1 && (
                                            <span className="text-muted-foreground font-mono text-xs">
                                                {' '}
                                                × {item.quantity}
                                            </span>
                                        )}
                                    </span>
                                    <span className="shrink-0 font-mono text-sm tabular-nums">
                                        {item.is_billable ? (
                                            formatCurrency(
                                                item.line_total,
                                                currency,
                                            )
                                        ) : (
                                            <span className="text-muted-foreground text-xs">
                                                Included
                                            </span>
                                        )}
                                    </span>
                                </li>
                            ))}
                            {retainer > 0 && (
                                <li className="flex items-baseline justify-between gap-4 px-5 py-3">
                                    <span className="text-sm">Retainer</span>
                                    <span className="font-mono text-sm tabular-nums">
                                        {formatCurrency(retainer, currency)}
                                    </span>
                                </li>
                            )}
                            {invoice.items.length === 0 && retainer === 0 && (
                                <li className="flex items-baseline justify-between gap-4 px-5 py-3">
                                    <span className="text-sm">
                                        {invoice.description ??
                                            'Professional services'}
                                    </span>
                                    <span className="font-mono text-sm tabular-nums">
                                        {formatCurrency(
                                            invoice.amount,
                                            currency,
                                        )}
                                    </span>
                                </li>
                            )}
                            <li className="bg-muted/40 flex items-baseline justify-between gap-4 px-5 py-3.5">
                                <span className="eyebrow text-muted-foreground">
                                    Total
                                </span>
                                <span className="font-display text-xl leading-none font-bold tabular-nums">
                                    {formatCurrency(invoice.amount, currency)}
                                </span>
                            </li>
                        </ul>
                    </Panel>

                    <div className="flex flex-col gap-5">
                        {studio.payment_instructions && (
                            <section className="bg-ink text-ink-foreground flex flex-col gap-3 rounded-2xl p-5">
                                <span className="eyebrow flex items-center gap-2 opacity-70">
                                    <Landmark className="text-lime size-3.5" />
                                    How to pay
                                </span>
                                <p className="text-sm leading-relaxed whitespace-pre-line">
                                    {studio.payment_instructions}
                                </p>
                                <p className="text-xs opacity-70">
                                    Please quote{' '}
                                    <span className="font-mono">
                                        {invoice.invoice_number}
                                    </span>{' '}
                                    as your reference.
                                </p>
                            </section>
                        )}
                        {invoice.description &&
                            (invoice.items.length > 0 || retainer > 0) && (
                                <Panel eyebrow="A note from the studio">
                                    <p className="text-sm whitespace-pre-line">
                                        {invoice.description}
                                    </p>
                                </Panel>
                            )}
                    </div>
                </div>

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
