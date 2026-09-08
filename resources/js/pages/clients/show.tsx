import { Form, Head, Link } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import ClientController from '@/actions/App/Http/Controllers/ClientController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { edit, index } from '@/routes/clients';

type ClientData = {
    id: number;
    name: string;
    legal_name: string | null;
    website: string | null;
    industry: string | null;
    countries: string[] | null;
    status: string;
    start_date: string | null;
    retainer_amount: string | null;
    billing_cycle: string | null;
    tags: string[] | null;
    default_language: string | null;
    notes_internal: string | null;
    approval_email: string | null;
    health: string;
    health_reason: string;
};

const statusVariant: Record<string, 'success' | 'warning' | 'secondary' | 'outline'> = {
    active: 'success',
    paused: 'warning',
    offboarding: 'secondary',
    archived: 'outline',
};

const healthVariant: Record<string, 'success' | 'warning' | 'destructive'> = {
    green: 'success',
    amber: 'warning',
    red: 'destructive',
};

export default function ClientShow({
    client,
    can,
}: {
    client: ClientData;
    can: { update: boolean; delete: boolean };
}) {
    return (
        <>
            <Head title={client.name} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
                <div className="flex items-start justify-between">
                    <Heading title={client.name} description={client.legal_name ?? undefined} />
                    <div className="flex items-center gap-2">
                        {can.update && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={edit(client.id)}>
                                    <Pencil />
                                    Edit
                                </Link>
                            </Button>
                        )}
                        {can.delete && (
                            <Form {...ClientController.destroy.form(client.id)}>
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        <Trash2 />
                                        Delete
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 rounded-lg border border-border bg-card p-4">
                    <div className="flex flex-wrap items-center gap-4">
                        <div className="flex items-center gap-2">
                            <span className="text-xs text-muted-foreground">Status</span>
                            <Badge variant={statusVariant[client.status] ?? 'outline'}>
                                {client.status}
                            </Badge>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="text-xs text-muted-foreground">Health</span>
                            <Badge
                                variant={healthVariant[client.health] ?? 'outline'}
                                title={client.health_reason}
                            >
                                {client.health}
                            </Badge>
                            <span className="text-xs text-muted-foreground">{client.health_reason}</span>
                        </div>
                    </div>

                    <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <div>
                            <dt className="text-xs text-muted-foreground">Website</dt>
                            <dd>{client.website ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">Industry</dt>
                            <dd>{client.industry ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">Countries</dt>
                            <dd>{client.countries?.join(', ') || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">Default language</dt>
                            <dd>{client.default_language ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">Start date</dt>
                            <dd>{client.start_date ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">Approval email</dt>
                            <dd>{client.approval_email ?? '—'}</dd>
                        </div>
                        {client.retainer_amount !== null && (
                            <div>
                                <dt className="text-xs text-muted-foreground">Retainer amount</dt>
                                <dd className="tabular-nums">{client.retainer_amount}</dd>
                            </div>
                        )}
                        {client.billing_cycle !== null && (
                            <div>
                                <dt className="text-xs text-muted-foreground">Billing cycle</dt>
                                <dd>{client.billing_cycle}</dd>
                            </div>
                        )}
                    </dl>

                    {client.notes_internal && (
                        <dl>
                            <dt className="text-xs text-muted-foreground">Internal notes</dt>
                            <dd className="mt-1 text-sm whitespace-pre-wrap">{client.notes_internal}</dd>
                        </dl>
                    )}
                </div>
            </div>
        </>
    );
}

ClientShow.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
