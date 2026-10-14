import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { create, index, store } from '@/routes/clients';

type Option = { value: string; label: string };

export default function ClientCreate({
    statuses,
    billingCycles,
    languages,
    owners,
}: {
    statuses: Option[];
    billingCycles: Option[];
    languages: Option[];
    owners: Option[];
}) {
    return (
        <>
            <Head title="Add client" />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
                <div className="bg-noise relative overflow-hidden rounded-2xl bg-gradient-brand-animated p-6 text-primary-foreground shadow-agency sm:p-8">
                    <span
                        aria-hidden
                        className="pointer-events-none absolute -top-16 -right-16 size-56 rounded-full bg-white/10 blur-2xl"
                    />
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 bg-[radial-gradient(white_1px,transparent_1px)] bg-[size:22px_22px] opacity-[0.08]"
                    />
                    <div className="relative flex flex-col gap-1">
                        <h1 className="font-serif text-2xl font-semibold tracking-tight italic sm:text-3xl">Add client</h1>
                        <p className="max-w-xl text-sm text-primary-foreground/80">Create a new client record</p>
                    </div>
                </div>

                <Form
                    {...store.form()}
                    transform={(data) => ({
                        ...data,
                        owner_user_id: data.owner_user_id === 'none' ? '' : data.owner_user_id,
                    })}
                    className="grid gap-6 rounded-2xl border border-border bg-card p-4 shadow-sm sm:p-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input id="name" name="name" required autoFocus />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="legal_name">Legal name</Label>
                                <Input id="legal_name" name="legal_name" />
                                <InputError message={errors.legal_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="website">Website</Label>
                                <Input id="website" name="website" type="url" placeholder="https://" />
                                <InputError message={errors.website} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="industry">Industry</Label>
                                <Input id="industry" name="industry" />
                                <InputError message={errors.industry} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="status">Status</Label>
                                <Select name="status" defaultValue="active">
                                    <SelectTrigger id="status" className="w-full">
                                        <SelectValue placeholder="Select a status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {statuses.map((status) => (
                                            <SelectItem key={status.value} value={status.value}>
                                                {status.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.status} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="owner_user_id">Owner</Label>
                                <Select name="owner_user_id" defaultValue="none">
                                    <SelectTrigger id="owner_user_id" className="w-full">
                                        <SelectValue placeholder="Select an owner" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">No owner</SelectItem>
                                        {owners.map((owner) => (
                                            <SelectItem key={owner.value} value={owner.value}>
                                                {owner.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.owner_user_id} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="start_date">Start date</Label>
                                <Input id="start_date" name="start_date" type="date" />
                                <InputError message={errors.start_date} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="retainer_amount">Retainer amount</Label>
                                <Input
                                    id="retainer_amount"
                                    name="retainer_amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                />
                                <InputError message={errors.retainer_amount} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="billing_cycle">Billing cycle</Label>
                                <Select name="billing_cycle">
                                    <SelectTrigger id="billing_cycle" className="w-full">
                                        <SelectValue placeholder="Select a billing cycle" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {billingCycles.map((cycle) => (
                                            <SelectItem key={cycle.value} value={cycle.value}>
                                                {cycle.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.billing_cycle} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="default_language">Default language</Label>
                                <Select name="default_language">
                                    <SelectTrigger id="default_language" className="w-full">
                                        <SelectValue placeholder="Select a language" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {languages.map((language) => (
                                            <SelectItem key={language.value} value={language.value}>
                                                {language.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.default_language} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="approval_email">Approval email</Label>
                                <Input id="approval_email" name="approval_email" type="email" />
                                <InputError message={errors.approval_email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="notes_internal">Internal notes</Label>
                                <textarea
                                    id="notes_internal"
                                    name="notes_internal"
                                    rows={4}
                                    className="border-input dark:bg-input/30 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                                />
                                <InputError message={errors.notes_internal} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button disabled={processing}>Create client</Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

ClientCreate.layout = {
    breadcrumbs: [
        { title: 'Clients', href: index() },
        { title: 'Add client', href: create() },
    ],
};
