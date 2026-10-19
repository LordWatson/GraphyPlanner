import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index, update } from '@/routes/clients';
import { PageHeader } from '@/components/page';

type Option = { value: string; label: string };

type ClientData = {
    id: number;
    name: string;
    legal_name: string | null;
    website: string | null;
    industry: string | null;
    status: string;
    owner_user_id: number | null;
    start_date: string | null;
    retainer_amount: string | null;
    billing_cycle: string | null;
    default_approval_mode: string;
    default_language: string | null;
    approval_email: string | null;
    billing_email: string | null;
    billing_address: string | null;
    notes_internal: string | null;
};

export default function ClientEdit({
    client,
    statuses,
    approvalModes = [],
    billingCycles,
    languages,
    owners,
}: {
    client: ClientData;
    statuses: Option[];
    approvalModes?: Option[];
    billingCycles: Option[];
    languages: Option[];
    owners: Option[];
}) {
    return (
        <>
            <Head title={`Edit ${client.name}`} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-8 px-4 py-6 md:px-8 md:py-9">
                <PageHeader
                    title={<>Edit {client.name}</>}
                    description="Keep the record current — status, retainer and contacts."
                />

                <Form
                    {...update.form(client.id)}
                    transform={(data) => ({
                        ...data,
                        owner_user_id:
                            data.owner_user_id === 'none'
                                ? ''
                                : data.owner_user_id,
                    })}
                    options={{ preserveScroll: true }}
                    className="border-border bg-card grid gap-6 rounded-2xl border p-4 shadow-sm sm:p-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={client.name}
                                    required
                                    autoFocus
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="legal_name">Legal name</Label>
                                <Input
                                    id="legal_name"
                                    name="legal_name"
                                    defaultValue={client.legal_name ?? ''}
                                />
                                <InputError message={errors.legal_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="website">Website</Label>
                                <Input
                                    id="website"
                                    name="website"
                                    type="url"
                                    defaultValue={client.website ?? ''}
                                />
                                <InputError message={errors.website} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="industry">Industry</Label>
                                <Input
                                    id="industry"
                                    name="industry"
                                    defaultValue={client.industry ?? ''}
                                />
                                <InputError message={errors.industry} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="status">Status</Label>
                                <Select
                                    name="status"
                                    defaultValue={client.status}
                                >
                                    <SelectTrigger
                                        id="status"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Select a status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {statuses.map((status) => (
                                            <SelectItem
                                                key={status.value}
                                                value={status.value}
                                            >
                                                {status.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.status} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="default_approval_mode">
                                    New posts need
                                </Label>
                                <Select
                                    name="default_approval_mode"
                                    defaultValue={client.default_approval_mode}
                                >
                                    <SelectTrigger
                                        id="default_approval_mode"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {approvalModes.map((mode) => (
                                            <SelectItem
                                                key={mode.value}
                                                value={mode.value}
                                            >
                                                {mode.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="text-muted-foreground text-xs">
                                    With client approval required, only the
                                    client (or an Owner, with a reason) can
                                    approve a post.
                                </p>
                                <InputError
                                    message={errors.default_approval_mode}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="owner_user_id">Owner</Label>
                                <Select
                                    name="owner_user_id"
                                    defaultValue={
                                        client.owner_user_id
                                            ? String(client.owner_user_id)
                                            : 'none'
                                    }
                                >
                                    <SelectTrigger
                                        id="owner_user_id"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Select an owner" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            No owner
                                        </SelectItem>
                                        {owners.map((owner) => (
                                            <SelectItem
                                                key={owner.value}
                                                value={owner.value}
                                            >
                                                {owner.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.owner_user_id} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="start_date">Start date</Label>
                                <Input
                                    id="start_date"
                                    name="start_date"
                                    type="date"
                                    defaultValue={client.start_date ?? ''}
                                />
                                <InputError message={errors.start_date} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="retainer_amount">
                                    Retainer amount
                                </Label>
                                <Input
                                    id="retainer_amount"
                                    name="retainer_amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    defaultValue={client.retainer_amount ?? ''}
                                />
                                <InputError message={errors.retainer_amount} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="billing_cycle">
                                    Billing cycle
                                </Label>
                                <Select
                                    name="billing_cycle"
                                    defaultValue={
                                        client.billing_cycle ?? undefined
                                    }
                                >
                                    <SelectTrigger
                                        id="billing_cycle"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Select a billing cycle" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {billingCycles.map((cycle) => (
                                            <SelectItem
                                                key={cycle.value}
                                                value={cycle.value}
                                            >
                                                {cycle.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.billing_cycle} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="default_language">
                                    Default language
                                </Label>
                                <Select
                                    name="default_language"
                                    defaultValue={
                                        client.default_language ?? undefined
                                    }
                                >
                                    <SelectTrigger
                                        id="default_language"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Select a language" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {languages.map((language) => (
                                            <SelectItem
                                                key={language.value}
                                                value={language.value}
                                            >
                                                {language.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.default_language} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="approval_email">
                                    Approval email
                                </Label>
                                <Input
                                    id="approval_email"
                                    name="approval_email"
                                    type="email"
                                    defaultValue={client.approval_email ?? ''}
                                />
                                <InputError message={errors.approval_email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="billing_email">
                                    Billing email
                                </Label>
                                <Input
                                    id="billing_email"
                                    name="billing_email"
                                    type="email"
                                    placeholder="accounts@client.com"
                                    defaultValue={client.billing_email ?? ''}
                                />
                                <p className="text-muted-foreground text-xs">
                                    Where invoices are emailed. Falls back to
                                    the approval email when blank.
                                </p>
                                <InputError message={errors.billing_email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="billing_address">
                                    Billing address
                                </Label>
                                <Textarea
                                    id="billing_address"
                                    name="billing_address"
                                    rows={3}
                                    placeholder="Printed in the invoice's “Billed to” block"
                                    defaultValue={client.billing_address ?? ''}
                                />
                                <InputError message={errors.billing_address} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="notes_internal">
                                    Internal notes
                                </Label>
                                <textarea
                                    id="notes_internal"
                                    name="notes_internal"
                                    rows={4}
                                    defaultValue={client.notes_internal ?? ''}
                                    className="border-input dark:bg-input/30 focus-visible:border-ring focus-visible:ring-ring/50 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                                />
                                <InputError message={errors.notes_internal} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button disabled={processing}>
                                    Save changes
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

ClientEdit.layout = {
    breadcrumbs: [
        { title: 'Clients', href: index() },
        { title: 'Edit client', href: index() },
    ],
};
