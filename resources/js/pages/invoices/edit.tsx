import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, FileUp, Lock } from 'lucide-react';
import InputError from '@/components/input-error';
import type {
    InvoiceAbilities,
    InvoiceClientSummary,
    InvoiceDetail,
} from '@/components/invoices/types';
import { Page, PageHeader, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatCurrency } from '@/lib/currency';
import {
    index as invoicesIndex,
    show as showInvoice,
    update as updateInvoice,
} from '@/routes/invoices';
import { store as storePdf } from '@/routes/invoices/pdf';

export default function InvoiceEdit({
    invoice,
    client,
    can,
}: {
    invoice: InvoiceDetail;
    client: InvoiceClientSummary;
    can: InvoiceAbilities;
}) {
    const { currency } = usePage<{ currency: string }>().props;

    return (
        <>
            <Head title={`Edit ${invoice.invoice_number} — ${client.name}`} />
            <Page width="narrow">
                <Link
                    href={showInvoice(invoice.id)}
                    className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" />
                    Back to {invoice.invoice_number}
                </Link>

                <PageHeader
                    eyebrow={
                        <>
                            <span className="font-mono">
                                {invoice.invoice_number}
                            </span>
                            <span aria-hidden>—</span>
                            {client.name}
                        </>
                    }
                    title={
                        <>
                            Edit <em>details</em>
                        </>
                    }
                    meta={
                        <StatusPill
                            kind="invoice"
                            value={invoice.display_status}
                            label={invoice.display_status_label}
                        />
                    }
                />

                <Panel
                    eyebrow="01 — Details"
                    title="Dates, amount and notes"
                    description="The invoice number and currency are set automatically."
                >
                    <Form
                        {...updateInvoice.form(invoice.id)}
                        className="grid gap-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="issue_date">
                                            Issue date
                                        </Label>
                                        <Input
                                            id="issue_date"
                                            name="issue_date"
                                            type="date"
                                            defaultValue={invoice.issue_date}
                                            required
                                        />
                                        <InputError
                                            message={errors.issue_date}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="due_date">
                                            Due date
                                        </Label>
                                        <Input
                                            id="due_date"
                                            name="due_date"
                                            type="date"
                                            defaultValue={
                                                invoice.due_date ?? ''
                                            }
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            Blank means due on receipt.
                                        </p>
                                        <InputError message={errors.due_date} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="amount">Amount</Label>
                                        {can.edit_amount ? (
                                            <Input
                                                id="amount"
                                                name="amount"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                defaultValue={invoice.amount}
                                            />
                                        ) : (
                                            <div className="border-input bg-muted/50 text-muted-foreground flex h-9 items-center gap-2 rounded-lg border px-3 font-mono text-sm tabular-nums">
                                                <Lock className="size-3.5" />
                                                {formatCurrency(
                                                    invoice.amount,
                                                    currency,
                                                )}
                                            </div>
                                        )}
                                        <p className="text-muted-foreground text-xs">
                                            {can.edit_amount
                                                ? 'A fixed amount — or add line items on the invoice page instead.'
                                                : invoice.is_finalized
                                                  ? 'Locked — computed from line items and the retainer.'
                                                  : 'Computed from line items when you finalize.'}
                                        </p>
                                        <InputError message={errors.amount} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="currency">
                                            Currency
                                        </Label>
                                        <Input
                                            id="currency"
                                            value={currency}
                                            disabled
                                            readOnly
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            Set at the organization level.
                                        </p>
                                    </div>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="description">
                                        Notes to the client
                                    </Label>
                                    <Textarea
                                        id="description"
                                        name="description"
                                        rows={4}
                                        defaultValue={invoice.description ?? ''}
                                        placeholder="Printed on the invoice — e.g. a summary of the month's work."
                                    />
                                    <InputError message={errors.description} />
                                </div>
                                <div>
                                    <Button type="submit" disabled={processing}>
                                        Save changes
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </Panel>

                <Panel
                    eyebrow="02 — Override"
                    title="Upload your own PDF"
                    icon={FileUp}
                    description={
                        can.generate_pdf
                            ? 'Graphy generates a branded PDF for you. Upload a file here only for one-off cases — it is never overwritten automatically.'
                            : 'Attach a PDF you made elsewhere. Once the invoice is finalized you can generate a branded one instead.'
                    }
                >
                    <div className="grid gap-4">
                        {invoice.pdf_url && (
                            <p className="text-muted-foreground text-sm">
                                Current file:{' '}
                                <a
                                    href={invoice.pdf_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-foreground underline underline-offset-2"
                                >
                                    {invoice.pdf_original_filename ??
                                        'invoice.pdf'}
                                </a>
                                {invoice.pdf_is_generated && ' (generated)'}
                            </p>
                        )}
                        <Form
                            {...storePdf.form(invoice.id)}
                            encType="multipart/form-data"
                            resetOnSuccess
                            className="flex flex-col gap-2 sm:flex-row sm:items-start"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid flex-1 gap-1.5">
                                        <Label
                                            htmlFor="pdf"
                                            className="sr-only"
                                        >
                                            PDF file
                                        </Label>
                                        <Input
                                            id="pdf"
                                            name="pdf"
                                            type="file"
                                            accept="application/pdf"
                                        />
                                        <InputError message={errors.pdf} />
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        {invoice.pdf_url
                                            ? 'Replace file'
                                            : 'Upload file'}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </div>
                </Panel>
            </Page>
        </>
    );
}

InvoiceEdit.layout = {
    breadcrumbs: [{ title: 'Invoices', href: invoicesIndex() }],
};
