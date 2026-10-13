import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, Pencil, Receipt, Trash2 } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCurrency } from '@/lib/currency';
import { index as indexClients, show as showClient } from '@/routes/clients';
import invoicesRoutes, {
    edit as editInvoice,
    destroy as destroyInvoice,
    send as sendInvoice,
    markPaid as markPaidInvoice,
    finalize as finalizeInvoice,
    generatePdf as generateInvoicePdf,
} from '@/routes/invoices';
import { update as updateInvoiceItem, destroy as destroyInvoiceItem } from '@/routes/invoice-items';

type InvoiceItemData = {
    id: number;
    description: string;
    is_billable: boolean;
    price: string | null;
    quantity: number;
    sort_order: number;
    line_total: number;
};

type InvoiceData = {
    id: number;
    invoice_number: string;
    status: 'draft' | 'sent' | 'paid';
    status_label: string;
    amount: string;
    currency: string;
    is_finalized: boolean;
    retainer_amount_snapshot: string | null;
    issue_date: string;
    due_date: string | null;
    description: string | null;
    sent_at: string | null;
    paid_at: string | null;
    pdf_url: string | null;
    pdf_original_filename: string | null;
    items: InvoiceItemData[];
    items_subtotal: number;
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
    can: {
        update: boolean;
        delete: boolean;
        send: boolean;
        mark_paid: boolean;
        finalize: boolean;
        manage_items: boolean;
        generate_pdf: boolean;
    };
}) {
    const { currency } = usePage().props;
    const [editingItemId, setEditingItemId] = useState<number | null>(null);

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
                                {formatCurrency(invoice.amount, currency)}
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
                        {can.finalize && (
                            <Form {...finalizeInvoice.form(invoice.id)}>
                                {({ processing }) => (
                                    <Button type="submit" size="sm" variant="outline" disabled={processing}>
                                        Finalize
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

                <div className="grid gap-4 rounded-lg border border-border bg-card p-4">
                    <Heading
                        title="Items"
                        description={
                            invoice.is_finalized
                                ? 'Finalized — edits here update the total automatically; regenerate the PDF to pick them up.'
                                : 'Billable and non-billable line items for this invoice.'
                        }
                    />

                    {invoice.items.length > 0 ? (
                        <div className="grid gap-2">
                            {invoice.items.map((item) =>
                                editingItemId === item.id ? (
                                    <Form
                                        key={item.id}
                                        {...updateInvoiceItem.form(item.id)}
                                        onSuccess={() => setEditingItemId(null)}
                                        className="grid gap-2 rounded-md border border-border p-3 sm:grid-cols-5 sm:items-end"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <div className="grid gap-1 sm:col-span-2">
                                                    <Label htmlFor={`description-${item.id}`}>Description</Label>
                                                    <Input
                                                        id={`description-${item.id}`}
                                                        name="description"
                                                        defaultValue={item.description}
                                                    />
                                                    <InputError message={errors.description} />
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Checkbox
                                                        id={`is_billable-${item.id}`}
                                                        name="is_billable"
                                                        value="1"
                                                        defaultChecked={item.is_billable}
                                                    />
                                                    <Label htmlFor={`is_billable-${item.id}`}>Billable</Label>
                                                </div>
                                                <div className="grid gap-1">
                                                    <Label htmlFor={`price-${item.id}`}>Price</Label>
                                                    <Input
                                                        id={`price-${item.id}`}
                                                        name="price"
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        defaultValue={item.price ?? ''}
                                                    />
                                                    <InputError message={errors.price} />
                                                </div>
                                                <div className="grid gap-1">
                                                    <Label htmlFor={`quantity-${item.id}`}>Quantity</Label>
                                                    <Input
                                                        id={`quantity-${item.id}`}
                                                        name="quantity"
                                                        type="number"
                                                        min="1"
                                                        defaultValue={item.quantity}
                                                    />
                                                    <InputError message={errors.quantity} />
                                                </div>
                                                <div className="flex items-center gap-2 sm:col-span-5">
                                                    <Button type="submit" size="sm" disabled={processing}>
                                                        Save
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => setEditingItemId(null)}
                                                    >
                                                        Cancel
                                                    </Button>
                                                </div>
                                            </>
                                        )}
                                    </Form>
                                ) : (
                                    <div
                                        key={item.id}
                                        className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border p-3"
                                    >
                                        <div className="grid gap-0.5">
                                            <span className="text-sm font-medium">{item.description}</span>
                                            <span className="text-xs text-muted-foreground">
                                                {item.is_billable
                                                    ? `${formatCurrency(item.price ?? '0', currency)} × ${item.quantity}`
                                                    : 'Not billable'}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <span className="text-sm font-medium tabular-nums">
                                                {formatCurrency(item.line_total, currency)}
                                            </span>
                                            {can.manage_items && (
                                                <div className="flex items-center gap-1">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => setEditingItemId(item.id)}
                                                    >
                                                        <Pencil />
                                                    </Button>
                                                    <Form {...destroyInvoiceItem.form(item.id)}>
                                                        {({ processing }) => (
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                variant="outline"
                                                                disabled={processing}
                                                            >
                                                                <Trash2 />
                                                            </Button>
                                                        )}
                                                    </Form>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ),
                            )}
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">No items added yet.</p>
                    )}

                    {can.manage_items && (
                        <Form
                            {...invoicesRoutes.items.store.form(invoice.id)}
                            resetOnSuccess
                            className="grid gap-2 rounded-md border border-dashed border-border p-3 sm:grid-cols-5 sm:items-end"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-1 sm:col-span-2">
                                        <Label htmlFor="new-description">Description</Label>
                                        <Input id="new-description" name="description" placeholder="e.g. Reel edit" />
                                        <InputError message={errors.description} />
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Checkbox id="new-is_billable" name="is_billable" value="1" defaultChecked />
                                        <Label htmlFor="new-is_billable">Billable</Label>
                                    </div>
                                    <div className="grid gap-1">
                                        <Label htmlFor="new-price">Price</Label>
                                        <Input id="new-price" name="price" type="number" step="0.01" min="0" />
                                        <InputError message={errors.price} />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label htmlFor="new-quantity">Quantity</Label>
                                        <Input id="new-quantity" name="quantity" type="number" min="1" defaultValue={1} />
                                        <InputError message={errors.quantity} />
                                    </div>
                                    <div className="sm:col-span-5">
                                        <Button type="submit" size="sm" disabled={processing}>
                                            Add item
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}

                    <div className="grid gap-1 border-t border-border pt-3">
                        <div className="flex items-center justify-between text-sm">
                            <span className="text-muted-foreground">Items subtotal</span>
                            <span className="font-medium tabular-nums">
                                {formatCurrency(invoice.items_subtotal, currency)}
                            </span>
                        </div>
                        {invoice.is_finalized && (
                            <>
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">Retainer</span>
                                    <span className="font-medium tabular-nums">
                                        {formatCurrency(invoice.retainer_amount_snapshot ?? '0', currency)}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between text-sm font-semibold">
                                    <span>Total</span>
                                    <span className="tabular-nums">{formatCurrency(invoice.amount, currency)}</span>
                                </div>
                            </>
                        )}
                    </div>
                </div>

                <div className="grid gap-3 rounded-lg border border-border bg-card p-4">
                    <Heading title="Invoice PDF" description="Uploaded by your organization" />
                    {can.generate_pdf && (
                        <Form {...generateInvoicePdf.form(invoice.id)}>
                            {({ processing }) => (
                                <Button type="submit" size="sm" variant="outline" disabled={processing} className="w-fit">
                                    Generate branded PDF
                                </Button>
                            )}
                        </Form>
                    )}
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
