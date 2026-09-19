import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { index as indexClients } from '@/routes/clients';
import { show as showCampaign, update as updateCampaign } from '@/routes/campaigns';

type CampaignData = {
    id: number;
    name: string;
    status: string;
    status_label: string;
    start_date: string | null;
    end_date: string | null;
    goal: string | null;
    notes: string | null;
};

type StatusOption = { value: string; label: string };

export default function CampaignEdit({
    campaign,
    client,
    statuses,
}: {
    campaign: CampaignData;
    client: { id: number; name: string };
    statuses: StatusOption[];
}) {
    return (
        <>
            <Head title={`Edit ${campaign.name} — ${client.name}`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
                <div className="flex flex-col gap-4">
                    <Link
                        href={showCampaign(campaign.id)}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Back to {campaign.name}
                    </Link>
                </div>

                <div className="relative overflow-hidden rounded-xl border border-border p-6 shadow-lg shadow-primary/10">
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 -z-10 bg-gradient-brand opacity-[0.14]"
                    />
                    <div className="flex flex-col gap-1">
                        <h1 className="font-serif text-2xl font-bold tracking-tight sm:text-3xl">
                            <span className="text-gradient-brand">Edit campaign</span>
                        </h1>
                        <p className="max-w-xl text-sm text-muted-foreground">
                            {campaign.name} — {client.name}
                        </p>
                    </div>
                </div>

                <Form {...updateCampaign.form(campaign.id)} className="grid gap-4 rounded-lg border border-border bg-card p-4">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1">
                                <Label htmlFor="name">Name</Label>
                                <Input id="name" name="name" defaultValue={campaign.name} required />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2 sm:grid-cols-2">
                                <div className="grid gap-1">
                                    <Label htmlFor="status">Status</Label>
                                    <Select name="status" defaultValue={campaign.status}>
                                        <SelectTrigger id="status" className="w-full">
                                            <SelectValue />
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
                                <div className="grid gap-1">
                                    <Label htmlFor="start_date">Start date</Label>
                                    <Input id="start_date" name="start_date" type="date" defaultValue={campaign.start_date ?? ''} />
                                    <InputError message={errors.start_date} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="end_date">End date</Label>
                                    <Input id="end_date" name="end_date" type="date" defaultValue={campaign.end_date ?? ''} />
                                    <InputError message={errors.end_date} />
                                </div>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="goal">Goal</Label>
                                <textarea
                                    id="goal"
                                    name="goal"
                                    rows={4}
                                    defaultValue={campaign.goal ?? ''}
                                    className="border-input dark:bg-input/30 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                                />
                                <InputError message={errors.goal} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="notes">Notes</Label>
                                <textarea
                                    id="notes"
                                    name="notes"
                                    rows={4}
                                    defaultValue={campaign.notes ?? ''}
                                    className="border-input dark:bg-input/30 flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                                />
                                <InputError message={errors.notes} />
                            </div>
                            <div>
                                <Button type="submit" disabled={processing}>
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

CampaignEdit.layout = {
    breadcrumbs: [{ title: 'Clients', href: indexClients() }, { title: 'Edit campaign', href: indexClients() }],
};
