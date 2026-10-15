import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as indexClients } from '@/routes/clients';
import {
    show as showInvoice,
    update as updateInvoice,
} from '@/routes/invoices';
import { store as storePdf } from '@/routes/invoices/pdf';
import { PageHeader } from '@/components/page';

type InvoiceData = {
    id: number;
    invoice_number: string;
    status: 'draft' | 'sent' | 'paid';
    status_label: string;
    amount: string;
    currency: string;
    is_finalized: boolean;
    issue_date: string;
    due_date: string | null;
    description: string | null;
    pdf_url: string | null;
    pdf_original_filename: string | null;
};

export default function InvoiceEdit({
    invoice,
    client,
}: {
    invoice: InvoiceData;
    client: { id: number; name: string };
}) {
    const { currency } = usePage().props;

    return (
        <>
            <Head
                title={`Edit invoice ${invoice.invoice_number} — ${client.name}`}
            />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-6 md:px-8 md:py-9">
                <div className="flex flex-col gap-4">
                    <Link
                        href={showInvoice(invoice.id)}
                        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm"
                    >
                        <ArrowLeft className="size-4" />
                        Back to {invoice.invoice_number}
                    </Link>
                </div>

                <PageHeader
                    title="Edit invoice"
                    description={
                        <>
                            {invoice.invoice_number} — {client.name}
                        </>
                    }
                />

                <Form
                    {...updateInvoice.form(invoice.id)}
                    className="border-border bg-card grid gap-4 rounded-2xl border p-4 shadow-sm"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <div className="grid gap-1">
                                    <Label htmlFor="amount">Amount</Label>
                                    <Input
                                        id="amount"
                                        name="amount"
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        defaultValue={invoice.amount}
                                        required
                                    />
                                    <InputError message={errors.amount} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="currency">Currency</Label>
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
                                <div className="grid gap-1">
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
                                    <InputError message={errors.issue_date} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="due_date">Due date</Label>
                                    <Input
                                        id="due_date"
                                        name="due_date"
                                        type="date"
                                        defaultValue={invoice.due_date ?? ''}
                                    />
                                    <InputError message={errors.due_date} />
                                </div>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="description">Description</Label>
                                <Input
                                    id="description"
                                    name="description"
                                    defaultValue={invoice.description ?? ''}
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

                <div className="border-border bg-card grid gap-3 rounded-2xl border p-4 shadow-sm">
                    <Label>Invoice PDF</Label>
                    {invoice.pdf_url && (
                        <div className="grid gap-2">
                            <p className="text-muted-foreground text-sm">
                                {invoice.pdf_original_filename ?? 'invoice.pdf'}
                            </p>
                            <iframe
                                src={invoice.pdf_url}
                                title="Invoice PDF"
                                className="border-border h-[50vh] w-full rounded-md border"
                            />
                        </div>
                    )}
                    {invoice.is_finalized ? (
                        <p className="text-muted-foreground text-xs">
                            This invoice is finalized — generate its branded PDF
                            from the invoice page. Manual upload below remains
                            available as a one-off override.
                        </p>
                    ) : null}
                    <Form
                        {...storePdf.form(invoice.id)}
                        encType="multipart/form-data"
                        resetOnSuccess
                        className="grid gap-2"
                    >
                        {({
                            processing: uploadProcessing,
                            errors: uploadErrors,
                        }) => (
                            <>
                                <Label htmlFor="pdf">
                                    {invoice.pdf_url
                                        ? 'Replace invoice PDF'
                                        : 'Upload invoice PDF'}
                                </Label>
                                <Input
                                    id="pdf"
                                    name="pdf"
                                    type="file"
                                    accept="application/pdf"
                                />
                                <InputError message={uploadErrors.pdf} />
                                <div>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        disabled={uploadProcessing}
                                    >
                                        {invoice.pdf_url
                                            ? 'Replace file'
                                            : 'Upload file'}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </div>
        </>
    );
}

InvoiceEdit.layout = {
    breadcrumbs: [
        { title: 'Clients', href: indexClients() },
        { title: 'Edit invoice', href: indexClients() },
    ],
};
