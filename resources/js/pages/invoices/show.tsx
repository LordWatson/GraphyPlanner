import { Form, Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Ban,
    Check,
    Copy,
    Download,
    FileText,
    Info,
    Lock,
    Pencil,
    Plus,
    RefreshCw,
    Trash2,
    X,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { ConfirmActionButton } from '@/components/invoices/confirm-action-button';
import {
    MarkPaidDialog,
    RemindInvoiceDialog,
    SendInvoiceDialog,
    VoidInvoiceDialog,
} from '@/components/invoices/invoice-action-dialogs';
import type {
    InvoiceAbilities,
    InvoiceClientSummary,
    InvoiceDetail,
    InvoiceItemData,
    InvoiceTimelineEvent,
} from '@/components/invoices/types';
import { formatInvoiceDate } from '@/components/invoices/types';
import { Page, PageHeader, Panel } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCurrency } from '@/lib/currency';
import { cn } from '@/lib/utils';
import { show as showClient, edit as editClient } from '@/routes/clients';
import {
    update as updateInvoiceItem,
    destroy as destroyInvoiceItem,
} from '@/routes/invoice-items';
import invoicesRoutes, {
    index as invoicesIndex,
    edit as editInvoice,
    destroy as destroyInvoice,
    finalize as finalizeInvoice,
    generatePdf as generateInvoicePdf,
    duplicate as duplicateInvoice,
} from '@/routes/invoices';

const headline: Record<InvoiceDetail['display_status'], [string, string]> = {
    draft: ['Draft', 'invoice'],
    sent: ['Awaiting', 'payment'],
    overdue: ['Payment', 'overdue'],
    paid: ['Paid in', 'full'],
    void: ['Invoice', 'void'],
};

export default function InvoiceShow({
    invoice,
    client,
    can,
}: {
    invoice: InvoiceDetail;
    client: InvoiceClientSummary;
    can: InvoiceAbilities;
}) {
    // Every figure on this page (items, retainer, total) is in the invoice's own currency.
    const currency = invoice.currency;
    const [lead, accent] = headline[invoice.display_status];
    const retainerPreview =
        invoice.retainer_amount_snapshot ??
        (invoice.status === 'draft' ? client.retainer_amount : null);
    const settled = invoice.status === 'paid' || invoice.status === 'void';

    return (
        <>
            <Head title={`${invoice.invoice_number} — ${client.name}`} />
            <Page width="wide">
                <Link
                    href={showClient(client.id)}
                    className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" />
                    {client.name}
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
                            {lead} <em>{accent}</em>
                        </>
                    }
                    meta={
                        <>
                            <StatusPill
                                kind="invoice"
                                value={invoice.display_status}
                                label={invoice.display_status_label}
                            />
                            {invoice.is_finalized && (
                                <span className="border-border bg-card text-muted-foreground inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs">
                                    <Lock className="size-3" />
                                    Total locked
                                </span>
                            )}
                            {invoice.sent_to_email && (
                                <span className="text-muted-foreground text-xs">
                                    Emailed to {invoice.sent_to_email}
                                </span>
                            )}
                        </>
                    }
                    actions={
                        <PrimaryActions
                            invoice={invoice}
                            client={client}
                            can={can}
                        />
                    }
                />

                <StateCallout invoice={invoice} can={can} />

                <div className="grid grid-cols-1 gap-5 lg:grid-cols-[1.45fr_1fr]">
                    <div className="flex min-w-0 flex-col gap-5">
                        <Panel
                            eyebrow="01 — Line items"
                            title="What's being billed"
                            description={
                                can.manage_items
                                    ? invoice.is_finalized
                                        ? 'Finalized — item edits re-total automatically. Regenerate the PDF afterwards.'
                                        : 'Billable items add to the total; non-billable ones show the client what was included.'
                                    : undefined
                            }
                            bodyClassName="p-0"
                        >
                            <ItemsTable
                                invoice={invoice}
                                canManage={can.manage_items}
                                currency={currency}
                            />
                            {can.manage_items && (
                                <AddItemForm invoiceId={invoice.id} />
                            )}
                            <Totals
                                invoice={invoice}
                                retainer={retainerPreview}
                                currency={currency}
                                settled={settled}
                            />
                        </Panel>

                        <Panel
                            eyebrow="02 — Document"
                            title="Invoice PDF"
                            icon={FileText}
                            actions={
                                <div className="flex items-center gap-2">
                                    {can.generate_pdf && (
                                        <ConfirmActionButton
                                            action={generateInvoicePdf(
                                                invoice.id,
                                            )}
                                            confirm={
                                                invoice.pdf_url &&
                                                !invoice.pdf_is_generated
                                                    ? {
                                                          title: 'Replace the uploaded PDF?',
                                                          description:
                                                              'The manually uploaded file will be replaced with a generated, branded PDF.',
                                                          confirmLabel:
                                                              'Replace',
                                                      }
                                                    : null
                                            }
                                            size="sm"
                                            variant="outline"
                                        >
                                            <RefreshCw />
                                            {invoice.pdf_url
                                                ? 'Regenerate'
                                                : 'Generate PDF'}
                                        </ConfirmActionButton>
                                    )}
                                    {invoice.pdf_url && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            asChild
                                        >
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
                                                Download
                                            </a>
                                        </Button>
                                    )}
                                </div>
                            }
                        >
                            {invoice.pdf_url ? (
                                <div className="flex flex-col gap-2">
                                    <p className="text-muted-foreground text-xs">
                                        {invoice.pdf_is_generated
                                            ? 'Generated by Graphy — refreshed automatically when the invoice is sent, paid or voided.'
                                            : `Uploaded manually (${invoice.pdf_original_filename ?? 'invoice.pdf'}) — kept as-is on status changes.`}
                                    </p>
                                    <iframe
                                        src={invoice.pdf_url}
                                        title={`Invoice ${invoice.invoice_number} PDF`}
                                        className="border-border bg-background h-[72vh] w-full rounded-xl border"
                                    />
                                </div>
                            ) : (
                                <p className="text-muted-foreground text-sm">
                                    {invoice.needs_finalizing
                                        ? 'Finalize the invoice to generate its branded PDF.'
                                        : can.generate_pdf
                                          ? 'No PDF yet — generate one, or it will be created automatically when you send the invoice.'
                                          : 'No PDF attached yet.'}{' '}
                                    {can.update && (
                                        <Link
                                            href={editInvoice(invoice.id)}
                                            className="underline underline-offset-2"
                                        >
                                            Upload your own instead.
                                        </Link>
                                    )}
                                </p>
                            )}
                        </Panel>
                    </div>

                    <aside className="flex min-w-0 flex-col gap-5">
                        <SpecSheet invoice={invoice} currency={currency} />

                        <Panel
                            eyebrow="Billed to"
                            title={client.legal_name ?? client.name}
                            actions={
                                can.update && (
                                    <Button size="sm" variant="ghost" asChild>
                                        <Link href={editClient(client.id)}>
                                            <Pencil />
                                            Edit
                                        </Link>
                                    </Button>
                                )
                            }
                        >
                            <dl className="grid gap-3 text-sm">
                                <div className="grid gap-0.5">
                                    <dt className="eyebrow text-muted-foreground">
                                        Invoices go to
                                    </dt>
                                    <dd>
                                        {client.recipient_email ?? (
                                            <span className="text-warning">
                                                No billing email — add one to
                                                email invoices.
                                            </span>
                                        )}
                                    </dd>
                                </div>
                                {client.billing_address && (
                                    <div className="grid gap-0.5">
                                        <dt className="eyebrow text-muted-foreground">
                                            Address
                                        </dt>
                                        <dd className="whitespace-pre-line">
                                            {client.billing_address}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        </Panel>

                        <Panel eyebrow="History" title="Timeline">
                            <Timeline events={invoice.timeline} />
                        </Panel>

                        <SecondaryActions invoice={invoice} can={can} />
                    </aside>
                </div>
            </Page>
        </>
    );
}

/** The one primary (orange) action for the invoice's current state, plus its companions. */
function PrimaryActions({
    invoice,
    client,
    can,
}: {
    invoice: InvoiceDetail;
    client: InvoiceClientSummary;
    can: InvoiceAbilities;
}) {
    return (
        <>
            {can.update && invoice.status === 'draft' && (
                <Button variant="outline" asChild>
                    <Link href={editInvoice(invoice.id)}>
                        <Pencil />
                        Edit details
                    </Link>
                </Button>
            )}
            {can.finalize && invoice.needs_finalizing && (
                <Form
                    {...finalizeInvoice.form(invoice.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button type="submit" disabled={processing}>
                            <Lock />
                            Finalize total
                        </Button>
                    )}
                </Form>
            )}
            {can.send && !invoice.needs_finalizing && (
                <SendInvoiceDialog
                    invoice={invoice}
                    defaultEmail={client.recipient_email}
                    size="default"
                    variant="default"
                />
            )}
            {can.remind && (
                <RemindInvoiceDialog
                    invoice={invoice}
                    defaultEmail={invoice.recipient_email}
                    isOverdue={invoice.is_overdue}
                    size="default"
                    variant="outline"
                />
            )}
            {can.mark_paid && (
                <MarkPaidDialog
                    invoice={invoice}
                    size="default"
                    variant="default"
                />
            )}
        </>
    );
}

function SecondaryActions({
    invoice,
    can,
}: {
    invoice: InvoiceDetail;
    can: InvoiceAbilities;
}) {
    if (!can.duplicate && !can.void && !can.delete && !can.finalize) {
        return null;
    }

    return (
        <div className="border-border flex flex-wrap items-center gap-2 rounded-2xl border border-dashed p-4">
            <span className="eyebrow text-muted-foreground w-full">
                More actions
            </span>
            {can.duplicate && (
                <Form {...duplicateInvoice.form(invoice.id)}>
                    {({ processing }) => (
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            disabled={processing}
                        >
                            <Copy />
                            Duplicate as new draft
                        </Button>
                    )}
                </Form>
            )}
            {can.finalize && !invoice.needs_finalizing && (
                <Form
                    {...finalizeInvoice.form(invoice.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            disabled={processing}
                        >
                            <Lock />
                            Finalize
                        </Button>
                    )}
                </Form>
            )}
            {can.void && (
                <VoidInvoiceDialog invoice={invoice} variant="ghost" />
            )}
            {can.delete && (
                <ConfirmActionButton
                    action={destroyInvoice(invoice.id)}
                    confirm={{
                        title: `Delete ${invoice.invoice_number}?`,
                        description:
                            'This permanently removes the invoice, its line items and its PDF.',
                        confirmLabel: 'Delete',
                        tone: 'destructive',
                    }}
                    size="sm"
                    variant="ghost"
                    className="text-destructive hover:text-destructive"
                >
                    <Trash2 />
                    Delete
                </ConfirmActionButton>
            )}
        </div>
    );
}

/** A single contextual note explaining what's blocking/next for this invoice. */
function StateCallout({
    invoice,
    can,
}: {
    invoice: InvoiceDetail;
    can: InvoiceAbilities;
}) {
    if (
        invoice.status === 'draft' &&
        invoice.needs_finalizing &&
        can.finalize
    ) {
        return (
            <Callout icon={Info} tone="info">
                Finalize to lock in the total (billable items plus the client's
                retainer) before sending. You can still adjust items afterwards.
            </Callout>
        );
    }

    if (invoice.is_overdue) {
        return (
            <Callout icon={AlertTriangle} tone="danger">
                {invoice.days_overdue} day
                {invoice.days_overdue === 1 ? '' : 's'} past due.{' '}
                {invoice.last_reminded_at
                    ? `Last reminder sent ${formatInvoiceDate(invoice.last_reminded_at)}${invoice.reminder_count > 1 ? ` (${invoice.reminder_count} so far)` : ''}.`
                    : 'No reminder sent yet.'}
            </Callout>
        );
    }

    if (invoice.status === 'void') {
        return (
            <Callout icon={Ban} tone="muted">
                Voided {formatInvoiceDate(invoice.voided_at)}
                {invoice.void_reason ? ` — ${invoice.void_reason}` : '.'}{' '}
                Nothing is owed and it's excluded from all totals.
            </Callout>
        );
    }

    return null;
}

function Callout({
    icon: Icon,
    tone,
    children,
}: {
    icon: typeof Info;
    tone: 'info' | 'danger' | 'muted';
    children: React.ReactNode;
}) {
    return (
        <div
            role="status"
            className={cn(
                'animate-rise flex items-start gap-3 rounded-2xl border px-4 py-3 text-sm',
                tone === 'info' && 'border-info/25 bg-info/5',
                tone === 'danger' && 'border-destructive/25 bg-destructive/5',
                tone === 'muted' && 'border-border bg-muted/60',
            )}
        >
            <Icon
                className={cn(
                    'mt-0.5 size-4 shrink-0',
                    tone === 'info' && 'text-info',
                    tone === 'danger' && 'text-destructive',
                    tone === 'muted' && 'text-muted-foreground',
                )}
            />
            <p>{children}</p>
        </div>
    );
}

/** The ink "amount" block and the key dates, read like a spec sheet. */
function SpecSheet({
    invoice,
    currency,
}: {
    invoice: InvoiceDetail;
    currency: string;
}) {
    const settled = invoice.status === 'paid' || invoice.status === 'void';
    const rows: [string, string][] = [
        ['Issued', formatInvoiceDate(invoice.issue_date)],
        [
            'Due',
            invoice.due_date
                ? formatInvoiceDate(invoice.due_date)
                : 'On receipt',
        ],
        ...(invoice.paid_at
            ? ([['Paid', formatInvoiceDate(invoice.paid_at)]] as [
                  string,
                  string,
              ][])
            : []),
        ...(invoice.payment_reference
            ? ([['Reference', invoice.payment_reference]] as [string, string][])
            : []),
    ];

    return (
        <section className="border-border bg-card overflow-hidden rounded-2xl border">
            <div
                className={cn(
                    'flex flex-col gap-2 p-5',
                    settled ? 'bg-muted' : 'bg-ink text-ink-foreground',
                )}
            >
                <span className="eyebrow opacity-70">
                    {invoice.status === 'paid'
                        ? 'Amount paid'
                        : invoice.status === 'void'
                          ? 'Amount (void)'
                          : 'Amount due'}
                </span>
                <span
                    className={cn(
                        'font-display text-4xl leading-none font-bold tabular-nums',
                        invoice.status === 'void' && 'line-through opacity-60',
                    )}
                >
                    {formatCurrency(invoice.amount, currency)}
                </span>
                {invoice.needs_finalizing && (
                    <span className="text-xs opacity-70">
                        Not final — finalize to compute from items.
                    </span>
                )}
            </div>
            <dl className="divide-border/70 divide-y">
                {rows.map(([label, value]) => (
                    <div
                        key={label}
                        className="flex items-center justify-between gap-4 px-5 py-3"
                    >
                        <dt className="eyebrow text-muted-foreground">
                            {label}
                        </dt>
                        <dd
                            className={cn(
                                'font-mono text-sm tabular-nums',
                                label === 'Due' &&
                                    invoice.is_overdue &&
                                    'text-destructive',
                            )}
                        >
                            {value}
                        </dd>
                    </div>
                ))}
            </dl>
            {invoice.description && (
                <div className="border-border/70 border-t px-5 py-4">
                    <span className="eyebrow text-muted-foreground">
                        Notes to client
                    </span>
                    <p className="mt-1 text-sm whitespace-pre-line">
                        {invoice.description}
                    </p>
                </div>
            )}
        </section>
    );
}

function Timeline({ events }: { events: InvoiceTimelineEvent[] }) {
    return (
        <ol className="relative flex flex-col gap-4">
            {events.map((event, index) => (
                <li key={event.event} className="relative flex gap-3">
                    {index < events.length - 1 && (
                        <span
                            aria-hidden
                            className="bg-border absolute top-4 left-[5px] h-[calc(100%+0.25rem)] w-px"
                        />
                    )}
                    <span
                        aria-hidden
                        className={cn(
                            'mt-1.5 size-[11px] shrink-0 rounded-full border-2',
                            event.event === 'paid'
                                ? 'border-success bg-success'
                                : event.event === 'voided'
                                  ? 'border-muted-foreground bg-muted'
                                  : index === events.length - 1
                                    ? 'border-primary bg-card'
                                    : 'border-foreground/30 bg-card',
                        )}
                    />
                    <div className="flex min-w-0 flex-col">
                        <span className="text-sm font-medium">
                            {event.label}
                        </span>
                        <span className="text-muted-foreground font-mono text-xs tabular-nums">
                            {new Date(event.at).toLocaleString('en-GB', {
                                day: 'numeric',
                                month: 'short',
                                year: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit',
                            })}
                        </span>
                        {event.detail && (
                            <span className="text-muted-foreground text-xs break-words">
                                {event.detail}
                            </span>
                        )}
                    </div>
                </li>
            ))}
        </ol>
    );
}

function ItemsTable({
    invoice,
    canManage,
    currency,
}: {
    invoice: InvoiceDetail;
    canManage: boolean;
    currency: string;
}) {
    const [editingId, setEditingId] = useState<number | null>(null);

    if (invoice.items.length === 0) {
        return (
            <p className="text-muted-foreground px-5 py-6 text-sm">
                {invoice.status === 'draft'
                    ? 'No line items yet. Add what you delivered below — or keep this as a fixed-amount invoice.'
                    : 'This is a fixed-amount invoice with no line items.'}
            </p>
        );
    }

    return (
        <div>
            <div className="eyebrow text-muted-foreground border-border/70 hidden grid-cols-[2rem_minmax(0,1fr)_3rem_6.5rem_7rem_4.5rem] gap-3 border-b px-5 py-2.5 sm:grid">
                <span>No.</span>
                <span>Description</span>
                <span className="text-right">Qty</span>
                <span className="text-right">Price</span>
                <span className="text-right">Amount</span>
                <span />
            </div>
            <ul className="divide-border/70 divide-y">
                {invoice.items.map((item, index) =>
                    editingId === item.id ? (
                        <li key={item.id} className="bg-muted/40 px-5 py-4">
                            <ItemForm
                                item={item}
                                onDone={() => setEditingId(null)}
                            />
                        </li>
                    ) : (
                        <ItemRow
                            key={item.id}
                            item={item}
                            index={index}
                            currency={currency}
                            canManage={canManage}
                            onEdit={() => setEditingId(item.id)}
                        />
                    ),
                )}
            </ul>
        </div>
    );
}

function ItemRow({
    item,
    index,
    currency,
    canManage,
    onEdit,
}: {
    item: InvoiceItemData;
    index: number;
    currency: string;
    canManage: boolean;
    onEdit: () => void;
}) {
    return (
        <li className="group hover:bg-muted/50 grid grid-cols-[2rem_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 px-5 py-3 transition-colors sm:grid-cols-[2rem_minmax(0,1fr)_3rem_6.5rem_7rem_4.5rem]">
            <span className="text-muted-foreground font-mono text-xs tabular-nums">
                {String(index + 1).padStart(2, '0')}
            </span>
            <span
                className={cn(
                    'min-w-0 text-sm',
                    !item.is_billable && 'text-muted-foreground',
                )}
            >
                {item.description}
                {!item.is_billable && (
                    <span className="bg-muted text-muted-foreground ml-2 inline-flex rounded-full px-2 py-0.5 align-middle text-[11px] font-medium">
                        Included
                    </span>
                )}
                <span className="text-muted-foreground block font-mono text-xs sm:hidden">
                    {item.is_billable
                        ? `${item.quantity} × ${formatCurrency(item.price ?? '0', currency)}`
                        : `${item.quantity} × not billed`}
                </span>
            </span>
            <span className="hidden text-right font-mono text-sm tabular-nums sm:block">
                {item.quantity}
            </span>
            <span className="text-muted-foreground hidden text-right font-mono text-sm tabular-nums sm:block">
                {item.is_billable
                    ? formatCurrency(item.price ?? '0', currency)
                    : '—'}
            </span>
            <span className="text-right font-mono text-sm tabular-nums">
                {item.is_billable
                    ? formatCurrency(item.line_total, currency)
                    : '—'}
            </span>
            <span className="col-span-full flex justify-end gap-0.5 sm:col-span-1">
                {canManage && (
                    <>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            className="size-8"
                            aria-label={`Edit ${item.description}`}
                            onClick={onEdit}
                        >
                            <Pencil className="size-3.5" />
                        </Button>
                        <ConfirmActionButton
                            action={destroyInvoiceItem(item.id)}
                            confirm={{
                                title: 'Remove this item?',
                                description: item.description,
                                confirmLabel: 'Remove',
                                tone: 'destructive',
                            }}
                            size="icon"
                            variant="ghost"
                            className="hover:text-destructive size-8"
                            aria-label={`Remove ${item.description}`}
                        >
                            <Trash2 className="size-3.5" />
                        </ConfirmActionButton>
                    </>
                )}
            </span>
        </li>
    );
}

/**
 * Shared fields for adding/editing an item. `is_billable` is sent through an explicit hidden
 * input — an unchecked Radix checkbox submits nothing, which the `required|boolean` rule rejects.
 */
function ItemFields({
    idPrefix,
    item,
    errors,
}: {
    idPrefix: string;
    item?: InvoiceItemData;
    errors: Record<string, string | undefined>;
}) {
    const [billable, setBillable] = useState(item?.is_billable ?? true);

    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-[minmax(0,1fr)_6.5rem_5rem]">
            <div className="col-span-2 grid gap-1.5 sm:col-span-1">
                <Label htmlFor={`${idPrefix}-description`}>Description</Label>
                <Input
                    id={`${idPrefix}-description`}
                    name="description"
                    defaultValue={item?.description}
                    placeholder="e.g. Reel edit — product launch"
                    autoFocus={item !== undefined}
                />
                <InputError message={errors.description} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={`${idPrefix}-price`}>Unit price</Label>
                <Input
                    id={`${idPrefix}-price`}
                    name="price"
                    type="number"
                    step="0.01"
                    min="0"
                    disabled={!billable}
                    placeholder={billable ? '0.00' : 'Included'}
                    defaultValue={item?.price ?? ''}
                />
                <InputError message={errors.price} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={`${idPrefix}-quantity`}>Qty</Label>
                <Input
                    id={`${idPrefix}-quantity`}
                    name="quantity"
                    type="number"
                    min="1"
                    defaultValue={item?.quantity ?? 1}
                />
                <InputError message={errors.quantity} />
            </div>
            <div className="col-span-2 flex items-center gap-2 sm:col-span-3">
                <input
                    type="hidden"
                    name="is_billable"
                    value={billable ? '1' : '0'}
                />
                <Checkbox
                    id={`${idPrefix}-billable`}
                    checked={billable}
                    onCheckedChange={(checked) => setBillable(checked === true)}
                />
                <Label htmlFor={`${idPrefix}-billable`} className="font-normal">
                    Billable{' '}
                    <span className="text-muted-foreground">
                        — untick for work that's included, shown to the client
                        but not charged
                    </span>
                </Label>
            </div>
        </div>
    );
}

function ItemForm({
    item,
    onDone,
}: {
    item: InvoiceItemData;
    onDone: () => void;
}) {
    return (
        <Form
            {...updateInvoiceItem.form(item.id)}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="grid gap-3"
        >
            {({ processing, errors }) => (
                <>
                    <ItemFields
                        idPrefix={`item-${item.id}`}
                        item={item}
                        errors={errors}
                    />
                    <InputError message={errors.invoice} />
                    <div className="flex gap-2">
                        <Button type="submit" size="sm" disabled={processing}>
                            <Check />
                            Save
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={onDone}
                        >
                            <X />
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function AddItemForm({ invoiceId }: { invoiceId: number }) {
    const [open, setOpen] = useState(false);

    if (!open) {
        return (
            <div className="border-border/70 border-t px-5 py-3">
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => setOpen(true)}
                >
                    <Plus />
                    Add line item
                </Button>
            </div>
        );
    }

    return (
        <Form
            {...invoicesRoutes.items.store.form(invoiceId)}
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="border-border/70 bg-muted/30 grid gap-3 border-t px-5 py-4"
        >
            {({ processing, errors }) => (
                <>
                    <span className="eyebrow text-muted-foreground">
                        New line item
                    </span>
                    <ItemFields idPrefix="new-item" errors={errors} />
                    <InputError message={errors.invoice} />
                    <div className="flex gap-2">
                        <Button type="submit" size="sm" disabled={processing}>
                            <Plus />
                            Add item
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setOpen(false)}
                        >
                            Done
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function Totals({
    invoice,
    retainer,
    currency,
    settled,
}: {
    invoice: InvoiceDetail;
    retainer: string | null;
    currency: string;
    settled: boolean;
}) {
    const retainerAmount = Number(retainer ?? 0);
    const hasItems = invoice.items.length > 0;
    const projected = invoice.is_finalized
        ? Number(invoice.amount)
        : hasItems
          ? invoice.items_subtotal + retainerAmount
          : Number(invoice.amount);

    return (
        <div className="border-border/70 flex justify-end border-t px-5 py-4">
            <dl className="grid w-full max-w-xs gap-1.5 text-sm">
                {hasItems && (
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">
                            Items subtotal
                        </dt>
                        <dd className="font-mono tabular-nums">
                            {formatCurrency(invoice.items_subtotal, currency)}
                        </dd>
                    </div>
                )}
                {retainerAmount > 0 && (hasItems || invoice.is_finalized) && (
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">
                            Retainer
                            {!invoice.is_finalized && (
                                <span className="text-xs"> (on finalize)</span>
                            )}
                        </dt>
                        <dd className="font-mono tabular-nums">
                            {formatCurrency(retainerAmount, currency)}
                        </dd>
                    </div>
                )}
                <div
                    className={cn(
                        'mt-1.5 flex items-center justify-between gap-4 rounded-xl px-4 py-3',
                        settled ? 'bg-muted' : 'bg-ink text-ink-foreground',
                    )}
                >
                    <dt className="eyebrow opacity-70">
                        {invoice.is_finalized || !hasItems
                            ? 'Total'
                            : 'Projected total'}
                    </dt>
                    <dd className="font-display text-2xl leading-none font-bold tabular-nums">
                        {formatCurrency(projected, currency)}
                    </dd>
                </div>
            </dl>
        </div>
    );
}

InvoiceShow.layout = {
    breadcrumbs: [{ title: 'Invoices', href: invoicesIndex() }],
};
