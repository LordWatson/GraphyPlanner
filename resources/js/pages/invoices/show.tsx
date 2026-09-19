import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Pencil, Receipt, Trash2 } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index as indexClients, show as showClient } from '@/routes/clients';
import { edit as editInvoice, destroy as destroyInvoice, send as sendInvoice, markPaid as markPaidInvoice } from '@/routes/invoices';

type InvoiceData = {
    id: number;
    invoice_number: string;
    status: 'draft' | 'sent' | 'paid';
    status_label: string;
    amount: string;
    currency: string;
    issue_date: string;
    due_date: string | null;
    description: string | null;
    sent_at: string | null;
    paid_at: string | null;
    pdf_url: string | null;
    pdf_original_filename: string | null;
};

const invoiceStatusVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline'> = {
    draft: 'secondary',
    sent: 'warning',
    paid: 'success',
};

export default function InvoiceShow({
    invoice,
    client,
    can,
}: {
    invoice: InvoiceData;
    client: { id: number; name: string };
    can: { update: boolean; delete: boolean; send: boolean; mark_paid: boolean };
}) {
    return (
        <>
            <Head title={`Invoice ${invoice.invoice_number} — ${client.name}`} />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4">
                <div className="flex flex-col gap-4">
                    <Link
                        href={showClient(client.id)}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Back to {client.name}
                    </Link>
                </div>

                <div className="relative overflow-hidden rounded-xl border border-border p-6 shadow-lg shadow-primary/10">
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 -z-10 bg-gradient-brand opacity-[0.14]"
                    />
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                <Receipt className="size-5" />
                            </span>
                            <div className="flex flex-col gap-1">
                                <h1 className="font-serif text-2xl font-bold tracking-tight sm:text-3xl">
                                    <span className="text-gradient-brand">{invoice.invoice_number}</span>
                                </h1>
                                <p className="text-sm text-muted-foreground">Client: {client.name}</p>
                            </div>
                        </div>
                        <Badge variant={invoiceStatusVariant[invoice.status] ?? 'outline'}>{invoice.status_label}</Badge>
                    </div>
                </div>

                <div className="grid gap-4 rounded-lg border border-border bg-card p-4">
                    <Heading title="Invoice details" />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1">
                            <span className="text-xs text-muted-foreground">Amount</span>
                            <span className="text-sm font-medium tabular-nums">
                                {invoice.currency} {invoice.amount}
                            </span>
                        </div>
                        <div className="grid gap-1">
                            <span className="text-xs text-muted-foreground">Issue date</span>
                            <span className="text-sm font-medium">{invoice.issue_date}</span>
                        </div>
                        <div className="grid gap-1">
                            <span className="text-xs text-muted-foreground">Due date</span>
                            <span className="text-sm font-medium">{invoice.due_date ?? '—'}</span>
                        </div>
                        <div className="grid gap-1">
                            <span className="text-xs text-muted-foreground">Description</span>
                            <span className="text-sm font-medium">{invoice.description ?? '—'}</span>
                        </div>
                        {invoice.sent_at && (
                            <div className="grid gap-1">
                                <span className="text-xs text-muted-foreground">Sent</span>
                                <span className="text-sm font-medium">{invoice.sent_at}</span>
                            </div>
                        )}
                        {invoice.paid_at && (
                            <div className="grid gap-1">
                                <span className="text-xs text-muted-foreground">Paid</span>
                                <span className="text-sm font-medium">{invoice.paid_at}</span>
                            </div>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {can.update && (
                            <Button asChild size="sm" variant="outline">
                                <Link href={editInvoice(invoice.id)}>
                                    <Pencil />
                                    Edit
                                </Link>
                            </Button>
                        )}
                        {can.send && (
                            <Form {...sendInvoice.form(invoice.id)}>
                                {({ processing }) => (
                                    <Button type="submit" size="sm" variant="outline" disabled={processing}>
                                        Send
                                    </Button>
                                )}
                            </Form>
                        )}
                        {can.mark_paid && (
                            <Form {...markPaidInvoice.form(invoice.id)}>
                                {({ processing }) => (
                                    <Button type="submit" size="sm" variant="outline" disabled={processing}>
                                        Mark paid
                                    </Button>
                                )}
                            </Form>
                        )}
                        {can.delete && (
                            <Form {...destroyInvoice.form(invoice.id)}>
                                {({ processing }) => (
                                    <Button type="submit" size="sm" variant="outline" disabled={processing}>
                                        <Trash2 />
                                        Delete
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                </div>

                <div className="grid gap-3 rounded-lg border border-border bg-card p-4">
                    <Heading title="Invoice PDF" description="Uploaded by your organization" />
                    {invoice.pdf_url ? (
                        <div className="grid gap-2">
                            <p className="text-sm text-muted-foreground">
                                {invoice.pdf_original_filename ?? 'invoice.pdf'}
                            </p>
                            <iframe
                                src={invoice.pdf_url}
                                title="Invoice PDF"
                                className="h-[70vh] w-full rounded-md border border-border"
                            />
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No PDF uploaded yet.{' '}
                            {can.update && (
                                <Link href={editInvoice(invoice.id)} className="underline">
                                    Upload one from the edit page.
                                </Link>
                            )}
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}

InvoiceShow.layout = {
    breadcrumbs: [{ title: 'Clients', href: indexClients() }],
};
