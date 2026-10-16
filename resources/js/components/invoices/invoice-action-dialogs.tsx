import { Form } from '@inertiajs/react';
import { Ban, BellRing, CircleCheck, Send } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatCurrency } from '@/lib/currency';
import invoices from '@/routes/invoices';
import { todayIso } from './types';

type InvoiceRef = {
    id: number;
    invoice_number: string;
    amount: string;
    currency: string;
};

type TriggerProps = {
    /** Render a custom trigger (defaults to a small outline button). */
    trigger?: ReactNode;
    size?: 'sm' | 'default';
    variant?: 'default' | 'outline' | 'secondary' | 'ghost';
};

/**
 * Must forward every prop: `DialogTrigger asChild` injects `onClick`, `ref`, `aria-*` and
 * `data-state` into its child, and dropping them leaves a button that never opens the dialog.
 */
function DefaultTrigger({
    icon,
    label,
    size = 'sm',
    variant = 'outline',
    ...props
}: {
    icon: ReactNode;
    label: string;
} & Pick<TriggerProps, 'size' | 'variant'> &
    Omit<ComponentProps<typeof Button>, 'size' | 'variant' | 'children'>) {
    return (
        <Button type="button" size={size} variant={variant} {...props}>
            {icon}
            {label}
        </Button>
    );
}

/**
 * Issue a draft: pick/confirm the recipient and add an optional note. With no recipient on file
 * the invoice is simply marked as sent (e.g. delivered by hand).
 */
export function SendInvoiceDialog({
    invoice,
    defaultEmail,
    trigger,
    size,
    variant = 'default',
}: {
    invoice: InvoiceRef;
    defaultEmail: string | null;
} & TriggerProps) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {trigger ?? (
                    <DefaultTrigger
                        icon={<Send />}
                        label="Send"
                        size={size}
                        variant={variant}
                    />
                )}
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Send {invoice.invoice_number}</DialogTitle>
                    <DialogDescription>
                        Emails the client a branded PDF for{' '}
                        {formatCurrency(invoice.amount, invoice.currency)} and
                        notifies their portal contacts. The invoice is locked
                        once sent.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...invoices.send.form(invoice.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`send-email-${invoice.id}`}>
                                    Send to
                                </Label>
                                <Input
                                    id={`send-email-${invoice.id}`}
                                    name="email"
                                    type="email"
                                    defaultValue={defaultEmail ?? ''}
                                    placeholder="accounts@client.com"
                                />
                                <p className="text-muted-foreground text-xs">
                                    {defaultEmail
                                        ? "Defaults to the client's billing contact."
                                        : 'No billing email on file — leave blank to mark as sent without emailing.'}
                                </p>
                                <InputError message={errors.email} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`send-message-${invoice.id}`}>
                                    Personal note{' '}
                                    <span className="text-muted-foreground font-normal">
                                        (optional)
                                    </span>
                                </Label>
                                <Textarea
                                    id={`send-message-${invoice.id}`}
                                    name="message"
                                    rows={3}
                                    placeholder="Thanks for a great month — here's October's invoice."
                                />
                                <InputError message={errors.message} />
                            </div>
                            <InputError message={errors.status} />
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    <Send />
                                    Send invoice
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/** Email a payment reminder for a sent invoice (throttled to one per day by the backend). */
export function RemindInvoiceDialog({
    invoice,
    defaultEmail,
    isOverdue,
    trigger,
    size,
    variant,
}: {
    invoice: InvoiceRef;
    defaultEmail: string | null;
    isOverdue: boolean;
} & TriggerProps) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {trigger ?? (
                    <DefaultTrigger
                        icon={<BellRing />}
                        label="Remind"
                        size={size}
                        variant={variant}
                    />
                )}
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Send a reminder</DialogTitle>
                    <DialogDescription>
                        {isOverdue
                            ? `${invoice.invoice_number} is overdue. We'll resend it with the PDF attached and a polite nudge.`
                            : `We'll resend ${invoice.invoice_number} with the PDF attached as a friendly reminder.`}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...invoices.remind.form(invoice.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`remind-email-${invoice.id}`}>
                                    Send to
                                </Label>
                                <Input
                                    id={`remind-email-${invoice.id}`}
                                    name="email"
                                    type="email"
                                    defaultValue={defaultEmail ?? ''}
                                    placeholder="accounts@client.com"
                                />
                                <InputError message={errors.email} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`remind-message-${invoice.id}`}>
                                    Personal note{' '}
                                    <span className="text-muted-foreground font-normal">
                                        (optional)
                                    </span>
                                </Label>
                                <Textarea
                                    id={`remind-message-${invoice.id}`}
                                    name="message"
                                    rows={3}
                                />
                                <InputError message={errors.message} />
                            </div>
                            <InputError message={errors.invoice} />
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    <BellRing />
                                    Send reminder
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/** Record a payment: when it arrived and an optional reference. */
export function MarkPaidDialog({
    invoice,
    trigger,
    size,
    variant,
}: {
    invoice: InvoiceRef;
} & TriggerProps) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {trigger ?? (
                    <DefaultTrigger
                        icon={<CircleCheck />}
                        label="Mark paid"
                        size={size}
                        variant={variant}
                    />
                )}
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Record payment</DialogTitle>
                    <DialogDescription>
                        Mark {invoice.invoice_number} (
                        {formatCurrency(invoice.amount, invoice.currency)}) as
                        paid. Its PDF is refreshed to double as a receipt.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...invoices.markPaid.form(invoice.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`paid-at-${invoice.id}`}>
                                        Payment date
                                    </Label>
                                    <Input
                                        id={`paid-at-${invoice.id}`}
                                        name="paid_at"
                                        type="date"
                                        max={todayIso()}
                                        defaultValue={todayIso()}
                                    />
                                    <InputError message={errors.paid_at} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`reference-${invoice.id}`}>
                                        Reference{' '}
                                        <span className="text-muted-foreground font-normal">
                                            (optional)
                                        </span>
                                    </Label>
                                    <Input
                                        id={`reference-${invoice.id}`}
                                        name="payment_reference"
                                        placeholder="e.g. BACS 88213"
                                    />
                                    <InputError
                                        message={errors.payment_reference}
                                    />
                                </div>
                            </div>
                            <InputError message={errors.status} />
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    <CircleCheck />
                                    Mark as paid
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/** Cancel a sent invoice while keeping it (and its number) on record. */
export function VoidInvoiceDialog({
    invoice,
    trigger,
    size,
    variant = 'ghost',
}: {
    invoice: InvoiceRef;
} & TriggerProps) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {trigger ?? (
                    <DefaultTrigger
                        icon={<Ban />}
                        label="Void"
                        size={size}
                        variant={variant}
                    />
                )}
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Void {invoice.invoice_number}?</DialogTitle>
                    <DialogDescription>
                        The client no longer owes this invoice. It stays on
                        record (and keeps its number) but drops out of every
                        outstanding and revenue total. This can't be undone.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...invoices.void.form(invoice.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`void-reason-${invoice.id}`}>
                                    Reason{' '}
                                    <span className="text-muted-foreground font-normal">
                                        (optional — printed on the PDF)
                                    </span>
                                </Label>
                                <Textarea
                                    id={`void-reason-${invoice.id}`}
                                    name="reason"
                                    rows={2}
                                    placeholder="e.g. Replaced by INV-000043."
                                />
                                <InputError message={errors.reason} />
                            </div>
                            <InputError message={errors.status} />
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                >
                                    Keep invoice
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    <Ban />
                                    Void invoice
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
