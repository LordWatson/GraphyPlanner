import { Form, router } from '@inertiajs/react';
import { Mail, Pencil, Phone, Trash2, UserRound } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
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
import { store as storeContact } from '@/routes/clients/contacts';
import {
    destroy as destroyContact,
    update as updateContact,
} from '@/routes/contacts';

export type ContactData = {
    id: number;
    name: string | null;
    email: string;
    phone: string | null;
    role: string;
    role_label: string;
    receives_review_emails: boolean;
    receives_invoices: boolean;
    can: { update: boolean; delete: boolean };
};

export type ContactRole = { value: string; label: string };

/**
 * A boolean form field submitted as "1"/"0" through a hidden input (so Laravel's `boolean` rule
 * accepts it), with a controlled shadcn Checkbox driving it — the project's checkbox convention.
 */
function BooleanField({
    name,
    label,
    checked,
    onChange,
}: {
    name: string;
    label: string;
    checked: boolean;
    onChange: (value: boolean) => void;
}) {
    return (
        <label className="flex items-center gap-2 text-sm">
            <input type="hidden" name={name} value={checked ? '1' : '0'} />
            <Checkbox
                checked={checked}
                onCheckedChange={(value) => onChange(value === true)}
            />
            {label}
        </label>
    );
}

/**
 * Step M-6.1 — manage a client's named contacts (approvers, billing, marketing leads, other). An
 * approver who receives review emails gets every post review link; a billing contact gets invoices.
 */
export function ClientContactsPanel({
    clientId,
    contacts,
    roles,
    canManage,
}: {
    clientId: number;
    contacts: ContactData[];
    roles: ContactRole[];
    canManage: boolean;
}) {
    const [editing, setEditing] = useState<ContactData | null>(null);

    return (
        <div className="grid gap-4">
            {canManage && <AddContactForm clientId={clientId} roles={roles} />}

            {contacts.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    No contacts yet. Add an approver so post review emails reach
                    the right people.
                </p>
            ) : (
                <div className="grid gap-3">
                    {contacts.map((contact) => (
                        <div
                            key={contact.id}
                            className="border-border bg-card hover:border-primary/30 flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3 shadow-sm transition-colors"
                        >
                            <div className="flex min-w-0 items-center gap-3">
                                <span className="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-full">
                                    <UserRound className="size-4" />
                                </span>
                                <div className="flex min-w-0 flex-col gap-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-sm font-medium">
                                            {contact.name ?? contact.email}
                                        </span>
                                        <Badge variant="secondary">
                                            {contact.role_label}
                                        </Badge>
                                        {contact.receives_review_emails && (
                                            <Badge variant="outline">
                                                Review emails
                                            </Badge>
                                        )}
                                        {contact.receives_invoices && (
                                            <Badge variant="outline">
                                                Invoices
                                            </Badge>
                                        )}
                                    </div>
                                    <span className="text-muted-foreground flex flex-wrap items-center gap-3 text-xs">
                                        <span className="inline-flex items-center gap-1">
                                            <Mail className="size-3" />
                                            {contact.email}
                                        </span>
                                        {contact.phone && (
                                            <span className="inline-flex items-center gap-1">
                                                <Phone className="size-3" />
                                                {contact.phone}
                                            </span>
                                        )}
                                    </span>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                {contact.can.update && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setEditing(contact)}
                                    >
                                        <Pencil />
                                        Edit
                                    </Button>
                                )}
                                {contact.can.delete && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => {
                                            if (
                                                window.confirm(
                                                    `Remove ${contact.name ?? contact.email}? They will stop receiving emails for this client.`,
                                                )
                                            ) {
                                                router.delete(
                                                    destroyContact(contact.id)
                                                        .url,
                                                    {
                                                        preserveScroll: true,
                                                    },
                                                );
                                            }
                                        }}
                                    >
                                        <Trash2 />
                                        Remove
                                    </Button>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <EditContactDialog
                contact={editing}
                roles={roles}
                onClose={() => setEditing(null)}
            />
        </div>
    );
}

function AddContactForm({
    clientId,
    roles,
}: {
    clientId: number;
    roles: ContactRole[];
}) {
    const [role, setRole] = useState('approver');
    const [reviewEmails, setReviewEmails] = useState(true);
    const [invoices, setInvoices] = useState(false);

    const reset = () => {
        setRole('approver');
        setReviewEmails(true);
        setInvoices(false);
    };

    // Role drives sensible defaults until the user overrides a box.
    const chooseRole = (value: string) => {
        setRole(value);
        setReviewEmails(value === 'approver');
        setInvoices(value === 'billing');
    };

    return (
        <Form
            {...storeContact.form(clientId)}
            resetOnSuccess
            onSuccess={reset}
            options={{ preserveScroll: true }}
            className="border-border bg-card/50 grid grid-cols-1 gap-3 rounded-2xl border border-dashed p-4 shadow-sm sm:grid-cols-2"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-1">
                        <Label htmlFor="contact-name">Name</Label>
                        <Input
                            id="contact-name"
                            name="name"
                            placeholder="e.g. Sarah Khan"
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="contact-email">Email</Label>
                        <Input
                            id="contact-email"
                            name="email"
                            type="email"
                            required
                        />
                        <InputError message={errors.email} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="contact-phone">Phone (optional)</Label>
                        <Input id="contact-phone" name="phone" />
                        <InputError message={errors.phone} />
                    </div>
                    <div className="grid gap-1">
                        <input type="hidden" name="role" value={role} />
                        <Label htmlFor="contact-role">Role</Label>
                        <Select value={role} onValueChange={chooseRole}>
                            <SelectTrigger id="contact-role" className="w-full">
                                <SelectValue placeholder="Choose a role" />
                            </SelectTrigger>
                            <SelectContent>
                                {roles.map((r) => (
                                    <SelectItem key={r.value} value={r.value}>
                                        {r.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.role} />
                    </div>
                    <div className="col-span-full flex flex-wrap items-center gap-6">
                        <BooleanField
                            name="receives_review_emails"
                            label="Receives post review emails"
                            checked={reviewEmails}
                            onChange={setReviewEmails}
                        />
                        <BooleanField
                            name="receives_invoices"
                            label="Receives invoices"
                            checked={invoices}
                            onChange={setInvoices}
                        />
                    </div>
                    <div className="col-span-full">
                        <Button type="submit" size="sm" disabled={processing}>
                            <UserRound />
                            Add contact
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function EditContactDialog({
    contact,
    roles,
    onClose,
}: {
    contact: ContactData | null;
    roles: ContactRole[];
    onClose: () => void;
}) {
    return (
        <Dialog
            open={contact !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Edit contact</DialogTitle>
                    <DialogDescription>
                        Update this contact's details and which emails they
                        receive.
                    </DialogDescription>
                </DialogHeader>
                {contact && (
                    <EditContactFields
                        key={contact.id}
                        contact={contact}
                        roles={roles}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function EditContactFields({
    contact,
    roles,
    onClose,
}: {
    contact: ContactData;
    roles: ContactRole[];
    onClose: () => void;
}) {
    const [role, setRole] = useState(contact.role);
    const [reviewEmails, setReviewEmails] = useState(
        contact.receives_review_emails,
    );
    const [invoices, setInvoices] = useState(contact.receives_invoices);

    return (
        <Form
            {...updateContact.form(contact.id)}
            options={{ preserveScroll: true }}
            onSuccess={onClose}
            className="grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-1">
                        <Label htmlFor="edit-name">Name</Label>
                        <Input
                            id="edit-name"
                            name="name"
                            defaultValue={contact.name ?? ''}
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="edit-email">Email</Label>
                        <Input
                            id="edit-email"
                            name="email"
                            type="email"
                            defaultValue={contact.email}
                            required
                        />
                        <InputError message={errors.email} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="edit-phone">Phone (optional)</Label>
                        <Input
                            id="edit-phone"
                            name="phone"
                            defaultValue={contact.phone ?? ''}
                        />
                        <InputError message={errors.phone} />
                    </div>
                    <div className="grid gap-1">
                        <input type="hidden" name="role" value={role} />
                        <Label htmlFor="edit-role">Role</Label>
                        <Select value={role} onValueChange={setRole}>
                            <SelectTrigger id="edit-role" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {roles.map((r) => (
                                    <SelectItem key={r.value} value={r.value}>
                                        {r.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.role} />
                    </div>
                    <div className="flex flex-wrap items-center gap-6">
                        <BooleanField
                            name="receives_review_emails"
                            label="Receives post review emails"
                            checked={reviewEmails}
                            onChange={setReviewEmails}
                        />
                        <BooleanField
                            name="receives_invoices"
                            label="Receives invoices"
                            checked={invoices}
                            onChange={setInvoices}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Save changes
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}
