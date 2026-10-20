import { useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { store as storeInvoice } from '@/routes/clients/invoices';
import type { CurrencyOption } from './types';
import { todayIso } from './types';
import { DatePicker } from '@/components/date-picker';

type Option = { value: string; label: string };

/**
 * Start a draft invoice, billed in the chosen currency (the studio's default unless changed —
 * every figure on the invoice then uses it). The amount is optional — most invoices are built from line items (and
 * the client's retainer) on the next screen; a fixed amount suits a simple one-off bill. Leaving
 * the due date empty applies the studio's default payment terms.
 */
export function NewInvoiceDialog({
    clients,
    clientId,
    currencies,
    defaultCurrency,
    trigger,
}: {
    /** Pick a client in the dialog (ledger page)… */
    clients?: Option[];
    /** …or create for a fixed client (client page). */
    clientId?: number;
    currencies: CurrencyOption[];
    /** The organization's default currency, preselected. */
    defaultCurrency: string;
    trigger?: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        client_id: clientId ? String(clientId) : '',
        currency: defaultCurrency,
        issue_date: todayIso(),
        due_date: '',
        amount: '',
        description: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!form.data.client_id) {
            form.setError('client_id', 'Choose a client.');

            return;
        }

        form.transform(({ client_id: _clientId, ...data }) => data);
        form.post(storeInvoice.url(Number(form.data.client_id)), {
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {trigger ?? (
                    <Button>
                        <Plus />
                        New invoice
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New invoice</DialogTitle>
                    <DialogDescription>
                        Creates a draft. Add line items next — the client's
                        retainer is added when you finalize.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    {clients && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="new-invoice-client">Client</Label>
                            <Select
                                value={form.data.client_id}
                                onValueChange={(value) =>
                                    form.setData('client_id', value)
                                }
                            >
                                <SelectTrigger
                                    id="new-invoice-client"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Choose a client" />
                                </SelectTrigger>
                                <SelectContent>
                                    {clients.map((client) => (
                                        <SelectItem
                                            key={client.value}
                                            value={client.value}
                                        >
                                            {client.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.client_id} />
                        </div>
                    )}
                    <div className="grid gap-1.5">
                        <Label htmlFor="new-invoice-currency">Currency</Label>
                        <Select
                            value={form.data.currency}
                            onValueChange={(value) =>
                                form.setData('currency', value)
                            }
                        >
                            <SelectTrigger
                                id="new-invoice-currency"
                                className="w-full"
                            >
                                <SelectValue placeholder="Choose a currency" />
                            </SelectTrigger>
                            <SelectContent>
                                {currencies.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.value} — {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-muted-foreground text-xs">
                            Every amount on this invoice is billed in this
                            currency.
                        </p>
                        <InputError message={form.errors.currency} />
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="new-invoice-issue">
                                Issue date
                            </Label>
                            <DatePicker
                                id="new-invoice-issue"
                                value={form.data.issue_date}
                                onChange={(value) =>
                                    form.setData('issue_date', value ?? '')
                                }
                            />
                            <InputError message={form.errors.issue_date} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="new-invoice-due">Due date</Label>
                            <DatePicker
                                id="new-invoice-due"
                                min={form.data.issue_date}
                                value={form.data.due_date}
                                onChange={(value) =>
                                    form.setData('due_date', value ?? '')
                                }
                            />
                            <p className="text-muted-foreground text-xs">
                                Blank = your default payment terms.
                            </p>
                            <InputError message={form.errors.due_date} />
                        </div>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="new-invoice-amount">
                            Fixed amount{' '}
                            <span className="text-muted-foreground font-normal">
                                ({form.data.currency}, optional)
                            </span>
                        </Label>
                        <Input
                            id="new-invoice-amount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            placeholder="Leave blank to build from line items"
                            value={form.data.amount}
                            onChange={(event) =>
                                form.setData('amount', event.target.value)
                            }
                        />
                        <InputError message={form.errors.amount} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="new-invoice-notes">
                            Notes to the client{' '}
                            <span className="text-muted-foreground font-normal">
                                (optional)
                            </span>
                        </Label>
                        <Textarea
                            id="new-invoice-notes"
                            rows={2}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                        <InputError message={form.errors.description} />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Create draft
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
