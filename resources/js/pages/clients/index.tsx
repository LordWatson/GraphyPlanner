import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, Building2, Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, index, show } from '@/routes/clients';

type ClientRow = {
    id: number;
    name: string;
    industry: string | null;
    status: string;
    default_language: string | null;
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

export default function ClientsIndex({
    clients,
    can,
}: {
    clients: ClientRow[];
    can: { create: boolean };
}) {
    return (
        <>
            <Head title="Clients" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="flex flex-col gap-1">
                        <span className="text-[10px] font-medium tracking-widest text-primary uppercase">Roster</span>
                        <h1 className="font-serif text-3xl font-semibold tracking-tight italic sm:text-4xl">
                            Client roster
                        </h1>
                        <p className="max-w-xl text-sm text-muted-foreground">
                            Every brand your team manages, with health and status at a glance — {clients.length}{' '}
                            total.
                        </p>
                    </div>
                    {can.create && (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus />
                                Add client
                            </Link>
                        </Button>
                    )}
                </div>

                {clients.length === 0 ? (
                    <div className="flex min-h-[40vh] flex-1 flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-border bg-card/50 p-8 text-center">
                        <Building2 className="size-6 text-muted-foreground" />
                        <p className="text-sm font-medium">No clients yet</p>
                        <p className="max-w-sm text-xs text-muted-foreground">
                            Add your first client to start organizing brands, campaigns, and posts.
                        </p>
                        {can.create && (
                            <Button asChild size="sm" className="mt-2">
                                <Link href={create()}>Add your first client</Link>
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {clients.map((client) => (
                            <Link
                                key={client.id}
                                href={show(client.id)}
                                className="group relative flex flex-col gap-4 overflow-hidden rounded-2xl border border-border bg-card p-5 shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-agency"
                            >
                                <span
                                    aria-hidden
                                    className="bg-gradient-brand absolute inset-x-0 top-0 h-1 opacity-70 transition-opacity group-hover:opacity-100"
                                />
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex items-center gap-3">
                                        <span className="bg-noise flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gradient-brand text-sm font-semibold text-primary-foreground">
                                            {client.name.slice(0, 2).toUpperCase()}
                                        </span>
                                        <div className="flex flex-col">
                                            <span className="font-serif text-lg font-semibold italic group-hover:text-primary">
                                                {client.name}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {client.industry ?? 'No industry set'}
                                            </span>
                                        </div>
                                    </div>
                                    <ArrowUpRight className="size-4 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant={statusVariant[client.status] ?? 'outline'}>{client.status}</Badge>
                                    <Badge variant={healthVariant[client.health] ?? 'outline'} title={client.health_reason}>
                                        {client.health}
                                    </Badge>
                                    {client.default_language && (
                                        <Badge variant="outline" className="text-[10px] uppercase">
                                            {client.default_language}
                                        </Badge>
                                    )}
                                </div>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

ClientsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Clients',
            href: index(),
        },
    ],
};
