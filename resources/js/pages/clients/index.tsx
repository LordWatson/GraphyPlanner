import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, Building2, Plus } from 'lucide-react';
import { EmptyState, Page, PageHeader } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { resolveStatus, toneBgClass } from '@/lib/status';
import { cn } from '@/lib/utils';
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

const healthOrder = ['red', 'amber', 'green'] as const;

export default function ClientsIndex({
    clients,
    can,
}: {
    clients: ClientRow[];
    can: { create: boolean };
}) {
    const healthCounts = healthOrder.map((health) => ({
        health,
        count: clients.filter((client) => client.health === health).length,
    }));

    return (
        <>
            <Head title="Clients" />
            <Page>
                <PageHeader
                    eyebrow={`Clients — ${String(clients.length).padStart(2, '0')} on the books`}
                    title={
                        <>
                            The <em>roster</em>
                        </>
                    }
                    description="Every brand your studio manages, with retainer health and status at a glance."
                    meta={
                        clients.length > 0 &&
                        healthCounts.map(({ health, count }) => (
                            <span
                                key={health}
                                className="border-border bg-card inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                            >
                                <span
                                    aria-hidden
                                    className={cn(
                                        'size-1.5 rounded-full',
                                        toneBgClass[
                                            resolveStatus('health', health).tone
                                        ],
                                    )}
                                />
                                <span className="font-mono tabular-nums">
                                    {count}
                                </span>
                                <span className="text-muted-foreground">
                                    {resolveStatus('health', health).label}
                                </span>
                            </span>
                        ))
                    }
                    actions={
                        can.create && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus />
                                    Add client
                                </Link>
                            </Button>
                        )
                    }
                />

                {clients.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title="No clients yet."
                        description="Add your first client to start organising brands, campaigns, and posts."
                        action={
                            can.create && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus />
                                        Add your first client
                                    </Link>
                                </Button>
                            )
                        }
                    />
                ) : (
                    <div className="animate-rise border-border bg-card overflow-hidden rounded-2xl border">
                        <div className="eyebrow border-border text-muted-foreground hidden grid-cols-[3rem_minmax(0,2fr)_minmax(0,1.2fr)_5rem_8rem_7rem_2rem] items-center gap-4 border-b px-5 py-3 md:grid">
                            <span>No.</span>
                            <span>Client</span>
                            <span>Industry</span>
                            <span>Lang</span>
                            <span>Status</span>
                            <span>Health</span>
                            <span />
                        </div>
                        <ul className="divide-border/70 divide-y">
                            {clients.map((client, i) => (
                                <li key={client.id}>
                                    <Link
                                        href={show(client.id)}
                                        className="group hover:bg-muted/50 grid grid-cols-[2.25rem_minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 px-5 py-4 transition-colors md:grid-cols-[3rem_minmax(0,2fr)_minmax(0,1.2fr)_5rem_8rem_7rem_2rem]"
                                    >
                                        <span className="text-muted-foreground font-mono text-xs tabular-nums">
                                            {String(i + 1).padStart(2, '0')}
                                        </span>
                                        <span className="flex min-w-0 items-center gap-3">
                                            <span className="border-border bg-background group-hover:border-primary group-hover:bg-primary group-hover:text-primary-foreground font-display flex size-9 shrink-0 items-center justify-center rounded-full border text-lg leading-none transition-colors">
                                                {client.name.charAt(0)}
                                            </span>
                                            <span className="group-hover:text-primary font-display truncate text-xl leading-tight transition-colors">
                                                {client.name}
                                            </span>
                                        </span>
                                        <ArrowUpRight className="text-muted-foreground group-hover:text-primary size-4 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 md:hidden" />
                                        <span className="text-muted-foreground col-start-2 truncate text-sm md:col-start-auto">
                                            {client.industry ?? '—'}
                                        </span>
                                        <span className="text-muted-foreground hidden font-mono text-xs uppercase md:inline">
                                            {client.default_language ?? '—'}
                                        </span>
                                        <span className="col-start-2 flex flex-wrap gap-1.5 md:col-start-auto md:contents">
                                            <span>
                                                <StatusPill
                                                    kind="client"
                                                    value={client.status}
                                                />
                                            </span>
                                            <span>
                                                <StatusPill
                                                    kind="health"
                                                    value={client.health}
                                                    title={client.health_reason}
                                                />
                                            </span>
                                        </span>
                                        <ArrowUpRight className="text-muted-foreground group-hover:text-primary hidden size-4 justify-self-end transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 md:block" />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </Page>
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
