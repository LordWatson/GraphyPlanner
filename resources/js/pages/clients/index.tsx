import { Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    ArrowUpRight,
    Building2,
    Plus,
    Search,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { confirmDialog } from '@/components/confirm-dialog';
import { EmptyState, Page, PageHeader } from '@/components/page';
import type { Paginated } from '@/components/pagination';
import { Pagination } from '@/components/pagination';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDate } from '@/lib/dates';
import { resolveStatus, toneBgClass } from '@/lib/status';
import { cn } from '@/lib/utils';
import { create, index, purge, restore, show } from '@/routes/clients';

type ClientRow = {
    id: number;
    name: string;
    industry: string | null;
    status: string;
    default_language: string | null;
    health: string;
    health_reason: string;
    owner_name: string | null;
    tags: string[];
    archived_at: string | null;
    has_invoices: boolean | null;
};

type RosterFilters = {
    archived: boolean;
    search: string | null;
    status: string | null;
    health: string | null;
    owner: number | null;
    tag: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    per_page: number;
};

type Option = { value: string; label: string };

type RosterOptions = {
    statuses: Option[];
    owners: Option[];
    sorts: string[];
    perPage: number[];
};

const healthOrder = ['red', 'amber', 'green'] as const;

const sortLabels: Record<string, string> = {
    name: 'Name',
    health: 'Health',
    owner: 'Owner',
    start_date: 'Start date',
    retainer: 'Retainer',
};

/** Step I-2.5: every roster change is a server round-trip — search, filter, sort, page. */
function visitRoster(
    filters: RosterFilters,
    changes: Partial<RosterFilters> & { page?: number },
) {
    const next: Record<string, unknown> = { ...filters, page: 1, ...changes };

    router.get(
        index().url,
        Object.fromEntries(
            Object.entries(next).filter(
                ([key, value]) =>
                    value !== null &&
                    value !== '' &&
                    value !== false &&
                    !(key === 'page' && value === 1),
            ),
        ) as Record<string, string | number | boolean>,
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

export default function ClientsIndex({
    clients,
    filters,
    filterOptions,
    healthCounts: storedHealthCounts,
    archivedCount,
    can,
}: {
    clients: Paginated<ClientRow>;
    filters: RosterFilters;
    filterOptions: RosterOptions;
    healthCounts: Record<(typeof healthOrder)[number], number>;
    archivedCount: number;
    can: { create: boolean; manageArchive: boolean };
}) {
    if (filters.archived) {
        return (
            <ArchivedClients clients={clients} filters={filters} can={can} />
        );
    }

    const healthCounts = healthOrder.map((health) => ({
        health,
        count: storedHealthCounts[health],
    }));
    const rosterSize = healthCounts.reduce((sum, { count }) => sum + count, 0);
    const filtered =
        filters.search !== null ||
        filters.status !== null ||
        filters.health !== null ||
        filters.owner !== null ||
        filters.tag !== null;

    return (
        <>
            <Head title="Clients" />
            <Page>
                <PageHeader
                    eyebrow={`${String(rosterSize).padStart(2, '0')} on the books`}
                    title={
                        <>
                            The <em>roster</em>
                        </>
                    }
                    description="Every brand your studio manages, with retainer health and status at a glance."
                    meta={
                        rosterSize > 0 &&
                        healthCounts.map(({ health, count }) => (
                            <button
                                type="button"
                                key={health}
                                aria-pressed={filters.health === health}
                                onClick={() =>
                                    visitRoster(filters, {
                                        health:
                                            filters.health === health
                                                ? null
                                                : health,
                                    })
                                }
                                className={cn(
                                    'border-border bg-card hover:border-foreground/25 inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors',
                                    filters.health === health &&
                                        'border-primary bg-primary/5',
                                )}
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
                            </button>
                        ))
                    }
                    actions={
                        <>
                            {archivedCount > 0 && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={index({
                                            query: { archived: 1 },
                                        })}
                                    >
                                        <Archive />
                                        Archived
                                        <span className="font-mono tabular-nums">
                                            {archivedCount}
                                        </span>
                                    </Link>
                                </Button>
                            )}
                            {can.create && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus />
                                        Add client
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                {rosterSize > 0 && (
                    <RosterToolbar filters={filters} options={filterOptions} />
                )}

                {clients.data.length === 0 && filtered ? (
                    <EmptyState
                        compact
                        icon={Search}
                        title="No matches."
                        description="No client matches these filters."
                        action={
                            <Button
                                variant="outline"
                                onClick={() =>
                                    visitRoster(filters, {
                                        search: null,
                                        status: null,
                                        health: null,
                                        owner: null,
                                        tag: null,
                                    })
                                }
                            >
                                Clear filters
                            </Button>
                        }
                    />
                ) : clients.data.length === 0 ? (
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
                            {clients.data.map((client, i) => (
                                <li key={client.id}>
                                    <Link
                                        href={show(client.id)}
                                        className="group hover:bg-muted/50 grid grid-cols-[2.25rem_minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 px-5 py-4 transition-colors md:grid-cols-[3rem_minmax(0,2fr)_minmax(0,1.2fr)_5rem_8rem_7rem_2rem]"
                                    >
                                        <span className="text-muted-foreground font-mono text-xs tabular-nums">
                                            {String(
                                                (clients.from ?? 1) + i,
                                            ).padStart(2, '0')}
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
                                            {client.owner_name && (
                                                <span className="block truncate text-xs">
                                                    {client.owner_name}
                                                </span>
                                            )}
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
                <Pagination
                    page={clients}
                    label="clients"
                    onPageChange={(page) => visitRoster(filters, { page })}
                />
            </Page>
        </>
    );
}

/**
 * Search, filter, sort and page-size controls for the roster.
 */
function RosterToolbar({
    filters,
    options,
}: {
    filters: RosterFilters;
    options: RosterOptions;
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const first = useRef(true);

    // Debounced so typing doesn't fire a request per keystroke.
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }

        const timeout = window.setTimeout(
            () => visitRoster(filters, { search: search.trim() || null }),
            300,
        );

        return () => window.clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <div className="border-border flex flex-wrap items-center gap-2 border-y py-3">
            <div className="relative w-full sm:w-64">
                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <Input
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Search name, legal name, tag"
                    aria-label="Search clients"
                    className="pl-9"
                />
            </div>

            <Select
                value={filters.status ?? 'all'}
                onValueChange={(value) =>
                    visitRoster(filters, {
                        status: value === 'all' ? null : value,
                    })
                }
            >
                <SelectTrigger size="sm" className="w-36" aria-label="Status">
                    <SelectValue placeholder="Status" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All statuses</SelectItem>
                    {options.statuses.map((status) => (
                        <SelectItem key={status.value} value={status.value}>
                            {status.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <Select
                value={filters.owner ? String(filters.owner) : 'all'}
                onValueChange={(value) =>
                    visitRoster(filters, {
                        owner: value === 'all' ? null : Number(value),
                    })
                }
            >
                <SelectTrigger size="sm" className="w-40" aria-label="Owner">
                    <SelectValue placeholder="Owner" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All owners</SelectItem>
                    {options.owners.map((owner) => (
                        <SelectItem key={owner.value} value={owner.value}>
                            {owner.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {filters.tag && (
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => visitRoster(filters, { tag: null })}
                >
                    Tag: {filters.tag} ×
                </Button>
            )}

            <span className="flex items-center gap-2 sm:ml-auto">
                <Select
                    value={`${filters.sort}:${filters.direction}`}
                    onValueChange={(value) => {
                        const [sort, direction] = value.split(':');
                        visitRoster(filters, {
                            sort,
                            direction: direction as 'asc' | 'desc',
                        });
                    }}
                >
                    <SelectTrigger size="sm" className="w-40" aria-label="Sort">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {options.sorts.flatMap((sort) =>
                            (['asc', 'desc'] as const).map((direction) => (
                                <SelectItem
                                    key={`${sort}:${direction}`}
                                    value={`${sort}:${direction}`}
                                >
                                    {sortLabels[sort] ?? sort}{' '}
                                    {direction === 'asc' ? '↑' : '↓'}
                                </SelectItem>
                            )),
                        )}
                    </SelectContent>
                </Select>

                <Select
                    value={String(filters.per_page)}
                    onValueChange={(value) =>
                        visitRoster(filters, { per_page: Number(value) })
                    }
                >
                    <SelectTrigger
                        size="sm"
                        className="w-28"
                        aria-label="Rows per page"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {options.perPage.map((size) => (
                            <SelectItem key={size} value={String(size)}>
                                {size} / page
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </span>
        </div>
    );
}

/**
 * Step I-1.5 — archived (soft-deleted) clients: restore, or purge for good once no invoices
 * remain (invoices are legal records and block a purge).
 */
function ArchivedClients({
    clients,
    filters,
    can,
}: {
    clients: Paginated<ClientRow>;
    filters: RosterFilters;
    can: { manageArchive: boolean };
}) {
    const restoreClient = (client: ClientRow) =>
        router.post(restore.url(client.id), {}, { preserveScroll: true });

    const purgeClient = async (client: ClientRow) => {
        const confirmed = await confirmDialog({
            title: `Delete ${client.name} for good?`,
            description:
                'Its posts, assets, campaigns and history are removed permanently, along with its stored files. This can’t be undone.',
            confirmLabel: 'Delete permanently',
            tone: 'destructive',
        });

        if (confirmed) {
            router.delete(purge.url(client.id), { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Archived clients" />
            <Page>
                <PageHeader
                    eyebrow={`${String(clients.total).padStart(2, '0')} archived`}
                    title="Archived clients."
                    description="Out of every list, with publishing paused. Restore one to pick up where you left off."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={index()}>Back to the roster</Link>
                        </Button>
                    }
                />

                {clients.data.length === 0 ? (
                    <EmptyState
                        icon={Archive}
                        title="Nothing archived."
                        description="Archived clients show up here, ready to restore."
                    />
                ) : (
                    <div className="animate-rise border-border bg-card overflow-hidden rounded-2xl border">
                        <ul className="divide-border/70 divide-y">
                            {clients.data.map((client) => (
                                <li
                                    key={client.id}
                                    className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4"
                                >
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <span className="font-display truncate text-xl leading-tight">
                                            {client.name}
                                        </span>
                                        <span className="text-muted-foreground text-sm">
                                            {client.archived_at
                                                ? `Archived ${formatDate(client.archived_at.slice(0, 10))}`
                                                : 'Archived'}
                                            {client.has_invoices &&
                                                ' · has invoices, so it can only be restored'}
                                        </span>
                                    </span>
                                    {can.manageArchive && (
                                        <span className="flex gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    restoreClient(client)
                                                }
                                            >
                                                <ArchiveRestore />
                                                Restore
                                            </Button>
                                            {!client.has_invoices && (
                                                <Button
                                                    variant="destructive"
                                                    size="sm"
                                                    onClick={() =>
                                                        void purgeClient(client)
                                                    }
                                                >
                                                    <Trash2 />
                                                    Delete permanently
                                                </Button>
                                            )}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                <Pagination
                    page={clients}
                    label="archived clients"
                    onPageChange={(page) => visitRoster(filters, { page })}
                />
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
