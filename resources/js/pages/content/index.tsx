import { Head, Link, router } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import {
    ArrowRightLeft,
    Bookmark,
    BookmarkPlus,
    CalendarClock,
    Copy,
    Download,
    FileText,
    Image as ImageIcon,
    KanbanSquare,
    LayoutGrid,
    MoreHorizontal,
    Plus,
    Search,
    SlidersHorizontal,
    Table as TableIcon,
    Trash2,
    UserRound,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { CampaignChip } from '@/components/campaigns/campaign-chip';
import { confirmDialog } from '@/components/confirm-dialog';
import { DataTable } from '@/components/data-table';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { KanbanColumn } from '@/components/kanban/kanban-board';
import { KanbanBoard } from '@/components/kanban/kanban-board';
import { ChapterHero, EmptyState, HeroStat, Page } from '@/components/page';
import type { Paginated } from '@/components/pagination';
import { platformIcon } from '@/components/platform-icon';
import { StatusPill } from '@/components/status-pill';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { formatDate, formatDateTime, formatSchedule } from '@/lib/dates';
import { http } from '@/lib/http';
import { resolveStatus, toneBgClass } from '@/lib/status';
import { cn } from '@/lib/utils';
import { store as storePost } from '@/routes/clients/posts';
import { bulk as bulkRoute, csv, index } from '@/routes/content';
import { index as gridsIndex } from '@/routes/grids';
import {
    store as storeView,
    destroy as destroyView,
} from '@/routes/content/views';
import { duplicate as duplicatePost, edit as editPost } from '@/routes/posts';

type Option = { value: string; label: string };
type CampaignOption = Option & { client_id: number };

type ChipData = {
    id: number;
    name: string;
    code: string | null;
    colour: string | null;
};
type TargetChip = { platform: string | null; handle: string | null };

type CoreCard = {
    id: number;
    status: string;
    status_label: string;
    master_caption: string | null;
    campaign: ChipData | null;
    thumbnail_url: string | null;
    targets: TargetChip[];
    scheduled_local_date: string | null;
    scheduled_local_time: string | null;
    scheduled_at_utc: string | null;
    client: { id: number; name: string | null };
};

type Row = CoreCard & {
    owner: string | null;
    assignee: string | null;
    due_at: string | null;
    has_media: boolean;
    updated_at: string | null;
    can: { update: boolean; duplicate: boolean };
};

type BoardCard = CoreCard & { drop_targets: string[] };
type BoardColumn = {
    key: string;
    label: string;
    drop_status: string | null;
    posts: BoardCard[];
};

type Filters = {
    search: string | null;
    client: number | null;
    campaign: number | null;
    assignee: number | null;
    status: string | null;
    platform: string | null;
    from: string | null;
    to: string | null;
    has_media: boolean | null;
};

type SavedView = { id: number; name: string; filters: Filters };

type Props = {
    view: 'table' | 'board';
    filters: Filters;
    posts: Paginated<Row> | null;
    board: { columns: BoardColumn[]; capped: boolean } | null;
    filterOptions: {
        statuses: Option[];
        platforms: Option[];
        clients: Option[];
        campaigns: CampaignOption[];
        staff: Option[];
    };
    savedViews: SavedView[];
    can: { create: boolean };
};

type BulkResult = { done: number[]; skipped: { id: number; reason: string }[] };

const ALL = '__all';

/** The statuses a bulk "Move to" offers — the board's droppable set (the server skips the rest). */
const MOVE_TARGETS = [
    'draft',
    'internal_review',
    'waiting_client',
    'changes_requested',
    'approved',
    'scheduled',
] as const;

/** Every filter change is a server round-trip, like the roster (I-2.5). Empty values drop out. */
function visit(current: Filters, view: string, changes: Partial<Filters>) {
    const next: Record<string, unknown> = {
        view,
        ...current,
        has_media:
            current.has_media === null
                ? null
                : current.has_media
                  ? 'yes'
                  : 'no',
        ...changes,
        page: 1,
    };

    router.get(
        index().url,
        Object.fromEntries(
            Object.entries(next).filter(
                ([, value]) =>
                    value !== null && value !== '' && value !== undefined,
            ),
        ) as Record<string, string | number>,
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

export default function ContentIndex({
    view,
    filters,
    posts,
    board,
    filterOptions,
    savedViews,
    can,
}: Props) {
    const total =
        posts?.total ??
        board?.columns.reduce((sum, c) => sum + c.posts.length, 0) ??
        0;
    const active =
        filters.search !== null ||
        filters.client !== null ||
        filters.campaign !== null ||
        filters.assignee !== null ||
        filters.status !== null ||
        filters.platform !== null ||
        filters.from !== null ||
        filters.to !== null ||
        filters.has_media !== null;

    const runBulk = async (
        action: string,
        ids: number[],
        value: string | number | null,
        onDone?: () => void,
    ) => {
        if (ids.length === 0) {
            return;
        }

        const result = await http.post<BulkResult>(bulkRoute().url, {
            action,
            post_ids: ids,
            value,
        });

        if (!result.ok) {
            toast.error(result.message);

            return;
        }

        const { done, skipped } = result.data;

        if (done.length > 0) {
            toast.success(
                `${done.length} ${done.length === 1 ? 'post' : 'posts'} updated.`,
            );
        }

        if (skipped.length > 0) {
            toast.warning(
                `${skipped.length} skipped: ${[...new Set(skipped.map((s) => s.reason))].join(' ')}`,
            );
        }

        onDone?.();
        router.reload({ only: ['posts', 'board'] });
    };

    const exportHref = csv({
        query: Object.fromEntries(
            Object.entries({
                search: filters.search,
                client: filters.client,
                campaign: filters.campaign,
                status: filters.status,
                platform: filters.platform,
                from: filters.from,
                to: filters.to,
                has_media:
                    filters.has_media === null
                        ? null
                        : filters.has_media
                          ? 'yes'
                          : 'no',
            }).filter(([, v]) => v !== null && v !== ''),
        ) as Record<string, string | number>,
    }).url;

    return (
        <>
            <Head title="Content" />
            <Page>
                <ChapterHero
                    eyebrow={`${String(total).padStart(2, '0')} posts`}
                    title={
                        <>
                            All your <em>content</em>.
                        </>
                    }
                    description="Every post across every client — filter it, move it in bulk, and plan it on the board."
                    actions={
                        can.create && (
                            <NewPostMenu
                                clients={filterOptions.clients}
                                variant="spark"
                                size="lg"
                            />
                        )
                    }
                    aside={
                        <HeroStat
                            icon={FileText}
                            eyebrow="In the pipeline"
                            value={String(total).padStart(2, '0')}
                            caption="Posts across every client"
                        />
                    }
                />

                <div className="animate-rise flex flex-wrap items-center gap-2 [animation-delay:80ms]">
                    <ViewSwitch view={view} filters={filters} />
                    <span className="flex flex-wrap items-center gap-2 sm:ml-auto">
                        <Button variant="outline" asChild>
                            <Link href={gridsIndex()}>
                                <LayoutGrid />
                                Grids
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <a href={exportHref}>
                                <Download />
                                Export CSV
                            </a>
                        </Button>
                    </span>
                </div>

                {view === 'board' ? (
                    <>
                        <div className="border-border border-y py-3">
                            <Toolbar
                                filters={filters}
                                view={view}
                                options={filterOptions}
                                savedViews={savedViews}
                                active={active}
                            />
                        </div>
                        {board && <Board board={board} runBulk={runBulk} />}
                    </>
                ) : (
                    posts && (
                        <DataTable
                            caption="Content"
                            className="animate-rise"
                            columns={columns}
                            data={posts.data}
                            getRowId={(post) => String(post.id)}
                            rowHref={(post) => editPost.url(post.id)}
                            storageKey="content"
                            pagination={posts}
                            paginationLabel="posts"
                            onPageChange={(page) =>
                                router.get(
                                    index().url,
                                    { ...cleanParams(filters, view), page },
                                    {
                                        preserveState: true,
                                        preserveScroll: true,
                                        replace: true,
                                    },
                                )
                            }
                            toolbar={
                                <Toolbar
                                    filters={filters}
                                    view={view}
                                    options={filterOptions}
                                    savedViews={savedViews}
                                    active={active}
                                />
                            }
                            empty={
                                <EmptyState
                                    compact={active}
                                    icon={active ? Search : FileText}
                                    title={
                                        active ? 'No matches.' : 'No posts yet.'
                                    }
                                    description={
                                        active
                                            ? 'No post matches these filters.'
                                            : 'Posts you create for any client show up here.'
                                    }
                                    action={
                                        active ? (
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    visit(
                                                        filters,
                                                        view,
                                                        emptyFilters(),
                                                    )
                                                }
                                            >
                                                Clear filters
                                            </Button>
                                        ) : can.create ? (
                                            // Momentum (ux-overhaul): the Content home never dead-ends
                                            // — offer the first post via the hub's own picker.
                                            <NewPostMenu
                                                clients={filterOptions.clients}
                                            />
                                        ) : undefined
                                    }
                                />
                            }
                            bulkActions={(selected, clear) => (
                                <BulkBar
                                    ids={selected.map((p) => p.id)}
                                    campaigns={filterOptions.campaigns}
                                    statuses={filterOptions.statuses}
                                    staff={filterOptions.staff}
                                    runBulk={runBulk}
                                    clear={clear}
                                />
                            )}
                        />
                    )
                )}
            </Page>
        </>
    );
}

const columns: ColumnDef<Row, unknown>[] = [
    {
        id: 'post',
        header: 'Post',
        enableHiding: false,
        cell: ({ row }) => (
            <Link
                href={editPost(row.original.id)}
                className="group flex min-w-0 items-center gap-3"
            >
                <Thumb url={row.original.thumbnail_url} />
                <span className="min-w-0">
                    <span className="group-hover:text-primary block max-w-md truncate text-sm font-medium transition-colors">
                        {row.original.master_caption || 'Untitled post'}
                    </span>
                    <span className="text-muted-foreground block truncate text-xs">
                        {row.original.client.name ?? '—'}
                    </span>
                </span>
            </Link>
        ),
    },
    {
        id: 'campaign',
        header: 'Campaign',
        meta: { hideBelow: 'lg' },
        cell: ({ row }) =>
            row.original.campaign ? (
                <CampaignChip campaign={row.original.campaign} compact />
            ) : (
                <span className="text-muted-foreground text-xs">—</span>
            ),
    },
    {
        id: 'status',
        header: 'Status',
        cell: ({ row }) => (
            <StatusPill
                kind="post"
                value={row.original.status}
                label={row.original.status_label}
            />
        ),
    },
    {
        id: 'channels',
        header: 'Channels',
        meta: { hideBelow: 'md' },
        cell: ({ row }) => (
            <span className="text-muted-foreground flex items-center gap-1.5">
                {row.original.targets.length === 0 ? (
                    <span className="text-xs">—</span>
                ) : (
                    row.original.targets.map((target, i) => {
                        const Icon = platformIcon(target.platform);

                        return (
                            <Icon
                                key={i}
                                className="size-4"
                                aria-label={target.platform ?? undefined}
                            />
                        );
                    })
                )}
            </span>
        ),
    },
    {
        id: 'schedule',
        header: 'Scheduled',
        meta: { hideBelow: 'sm', className: 'font-mono text-xs tabular-nums' },
        cell: ({ row }) =>
            formatSchedule(
                row.original.scheduled_local_date,
                row.original.scheduled_local_time,
                'Unscheduled',
            ),
    },
    {
        id: 'assignee',
        header: 'Assignee',
        meta: { hideBelow: 'lg', className: 'text-muted-foreground text-sm' },
        cell: ({ row }) => (
            <>
                <span className="block truncate">
                    {row.original.assignee ?? 'Unassigned'}
                </span>
                {row.original.due_at && (
                    <span className="block font-mono text-xs">
                        Due {formatDate(row.original.due_at)}
                    </span>
                )}
            </>
        ),
    },
    {
        id: 'owner',
        header: 'Owner',
        meta: { hideBelow: 'lg', className: 'text-muted-foreground text-sm' },
        cell: ({ row }) => row.original.owner ?? '—',
    },
    {
        id: 'updated',
        header: 'Updated',
        meta: {
            hideBelow: 'lg',
            className: 'text-muted-foreground font-mono text-xs',
        },
        cell: ({ row }) => formatDateTime(row.original.updated_at),
    },
    {
        id: 'actions',
        header: () => <span className="sr-only">Actions</span>,
        enableHiding: false,
        meta: { className: 'w-0 whitespace-nowrap' },
        cell: ({ row }) =>
            row.original.can.duplicate && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-8"
                            aria-label={`Actions for ${row.original.master_caption || 'post'}`}
                        >
                            <MoreHorizontal className="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            onSelect={() =>
                                router.post(duplicatePost(row.original.id).url)
                            }
                        >
                            <Copy />
                            Duplicate
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            ),
    },
];

function cleanParams(
    filters: Filters,
    view: string,
): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries({
            view,
            ...filters,
            has_media:
                filters.has_media === null
                    ? null
                    : filters.has_media
                      ? 'yes'
                      : 'no',
        }).filter(([, v]) => v !== null && v !== ''),
    ) as Record<string, string | number>;
}

function Thumb({ url }: { url: string | null }) {
    return url ? (
        <img
            src={url}
            alt=""
            className="border-border size-9 shrink-0 rounded-lg border object-cover"
        />
    ) : (
        <span className="border-border bg-muted text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-lg border">
            <ImageIcon className="size-4" />
        </span>
    );
}

function NewPostMenu({
    clients,
    variant,
    size,
}: {
    clients: Option[];
    variant?: 'default' | 'spark';
    size?: 'default' | 'lg';
}) {
    if (clients.length === 0) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant={variant} size={size}>
                    <Plus />
                    New post
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="max-h-80 w-56 overflow-y-auto"
            >
                <DropdownMenuLabel>New post for…</DropdownMenuLabel>
                {clients.map((client) => (
                    <DropdownMenuItem
                        key={client.value}
                        onSelect={() =>
                            router.post(storePost(Number(client.value)).url)
                        }
                    >
                        {client.label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function ViewSwitch({ view, filters }: { view: string; filters: Filters }) {
    return (
        <div className="bg-muted inline-flex rounded-full p-0.5">
            {(
                [
                    { key: 'table', label: 'Table', icon: TableIcon },
                    { key: 'board', label: 'Board', icon: LayoutGrid },
                ] as const
            ).map(({ key, label, icon: Icon }) => (
                <button
                    key={key}
                    type="button"
                    aria-pressed={view === key}
                    onClick={() => view !== key && visit(filters, key, {})}
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium transition-colors',
                        view === key
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    <Icon className="size-3.5" />
                    {label}
                </button>
            ))}
        </div>
    );
}

function Toolbar({
    filters,
    view,
    options,
    savedViews,
    active,
}: {
    filters: Filters;
    view: string;
    options: Props['filterOptions'];
    savedViews: SavedView[];
    active: boolean;
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [saveOpen, setSaveOpen] = useState(false);
    const [name, setName] = useState('');
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const timeout = window.setTimeout(
            () => visit(filters, view, { search: search.trim() || null }),
            300,
        );

        return () => window.clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    // A campaign filter only makes sense for its own client's posts.
    const campaigns = filters.client
        ? options.campaigns.filter((c) => c.client_id === filters.client)
        : options.campaigns;

    const saveCurrentView = () => {
        if (!name.trim()) {
            return;
        }

        router.post(
            storeView().url,
            { name: name.trim(), filters },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setSaveOpen(false);
                    setName('');
                },
            },
        );
    };

    const chips = activeChips(filters, campaigns, options);
    const filterCount = chips.length;

    const clearAll = () => {
        setSearch('');
        visit(filters, view, emptyFilters());
    };

    return (
        <div className="flex w-full flex-col gap-2">
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative w-full sm:w-64">
                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                    <Input
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search captions"
                        aria-label="Search posts"
                        className="pl-9"
                    />
                </div>

                <Sheet>
                    <SheetTrigger asChild>
                        <Button variant="outline" size="sm">
                            <SlidersHorizontal />
                            Filters
                            {filterCount > 0 && (
                                <Badge
                                    variant="secondary"
                                    className="ml-0.5 rounded-full px-1.5 font-mono tabular-nums"
                                >
                                    {filterCount}
                                </Badge>
                            )}
                        </Button>
                    </SheetTrigger>
                    <SheetContent className="flex w-full flex-col gap-0 sm:max-w-sm">
                        <SheetHeader>
                            <SheetTitle>Filters</SheetTitle>
                            <SheetDescription>
                                Narrow the list. Active filters show as chips
                                you can remove one at a time.
                            </SheetDescription>
                        </SheetHeader>
                        <div className="grid gap-4 overflow-y-auto px-4 pb-6">
                            <FilterField label="Client">
                                <FilterSelect
                                    label="Client"
                                    triggerClassName="w-full"
                                    value={
                                        filters.client
                                            ? String(filters.client)
                                            : ALL
                                    }
                                    onChange={(value) =>
                                        visit(filters, view, {
                                            client:
                                                value === ALL
                                                    ? null
                                                    : Number(value),
                                            campaign: null,
                                            assignee: null,
                                        })
                                    }
                                    options={options.clients}
                                />
                            </FilterField>
                            <FilterField label="Campaign">
                                <FilterSelect
                                    label="Campaign"
                                    triggerClassName="w-full"
                                    value={
                                        filters.campaign
                                            ? String(filters.campaign)
                                            : ALL
                                    }
                                    onChange={(value) =>
                                        visit(filters, view, {
                                            campaign:
                                                value === ALL
                                                    ? null
                                                    : Number(value),
                                        })
                                    }
                                    options={campaigns}
                                />
                            </FilterField>
                            <FilterField label="Assignee">
                                <FilterSelect
                                    label="Assignee"
                                    triggerClassName="w-full"
                                    value={
                                        filters.assignee === null
                                            ? ALL
                                            : String(filters.assignee)
                                    }
                                    onChange={(value) =>
                                        visit(filters, view, {
                                            assignee:
                                                value === ALL
                                                    ? null
                                                    : Number(value),
                                        })
                                    }
                                    options={[
                                        { value: '0', label: 'Unassigned' },
                                        ...options.staff,
                                    ]}
                                />
                            </FilterField>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <FilterField label="Status">
                                    <FilterSelect
                                        label="Status"
                                        triggerClassName="w-full"
                                        value={filters.status ?? ALL}
                                        onChange={(value) =>
                                            visit(filters, view, {
                                                status:
                                                    value === ALL
                                                        ? null
                                                        : value,
                                            })
                                        }
                                        options={options.statuses}
                                    />
                                </FilterField>
                                <FilterField label="Platform">
                                    <FilterSelect
                                        label="Platform"
                                        triggerClassName="w-full"
                                        value={filters.platform ?? ALL}
                                        onChange={(value) =>
                                            visit(filters, view, {
                                                platform:
                                                    value === ALL
                                                        ? null
                                                        : value,
                                            })
                                        }
                                        options={options.platforms}
                                    />
                                </FilterField>
                            </div>
                            <FilterField label="Media">
                                <FilterSelect
                                    label="Media"
                                    triggerClassName="w-full"
                                    value={
                                        filters.has_media === null
                                            ? ALL
                                            : filters.has_media
                                              ? 'yes'
                                              : 'no'
                                    }
                                    onChange={(value) =>
                                        visit(filters, view, {
                                            has_media:
                                                value === ALL
                                                    ? null
                                                    : value === 'yes',
                                        })
                                    }
                                    options={[
                                        { value: 'yes', label: 'Has media' },
                                        { value: 'no', label: 'No media' },
                                    ]}
                                />
                            </FilterField>
                            <FilterField label="Scheduled between">
                                <div className="flex items-center gap-2">
                                    <Input
                                        type="date"
                                        value={filters.from ?? ''}
                                        onChange={(event) =>
                                            visit(filters, view, {
                                                from:
                                                    event.target.value || null,
                                            })
                                        }
                                        aria-label="Scheduled from"
                                        className="h-9"
                                    />
                                    <span className="text-muted-foreground">
                                        →
                                    </span>
                                    <Input
                                        type="date"
                                        value={filters.to ?? ''}
                                        onChange={(event) =>
                                            visit(filters, view, {
                                                to: event.target.value || null,
                                            })
                                        }
                                        aria-label="Scheduled to"
                                        className="h-9"
                                    />
                                </div>
                            </FilterField>
                            {filterCount > 0 && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="justify-self-start"
                                    onClick={clearAll}
                                >
                                    <X />
                                    Clear all filters
                                </Button>
                            )}
                        </div>
                    </SheetContent>
                </Sheet>

                <span className="flex items-center gap-2 sm:ml-auto">
                    <SavedViews
                        views={savedViews}
                        filters={filters}
                        view={view}
                        onSave={() => setSaveOpen(true)}
                    />
                    <Dialog open={saveOpen} onOpenChange={setSaveOpen}>
                        <DialogContent className="sm:max-w-sm">
                            <DialogHeader>
                                <DialogTitle>Save this view</DialogTitle>
                                <DialogDescription>
                                    Keep the current filters one click away.
                                    Views are yours alone.
                                </DialogDescription>
                            </DialogHeader>
                            <Input
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                placeholder="e.g. Waiting on clients this week"
                                aria-label="View name"
                                maxLength={60}
                                onKeyDown={(event) =>
                                    event.key === 'Enter' && saveCurrentView()
                                }
                            />
                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    onClick={() => setSaveOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    onClick={saveCurrentView}
                                    disabled={!name.trim()}
                                >
                                    Save view
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </span>
            </div>

            {chips.length > 0 && (
                <div className="flex flex-wrap items-center gap-1.5">
                    {chips.map((chip) => (
                        <button
                            key={chip.key}
                            type="button"
                            onClick={() =>
                                visit(filters, view, { [chip.key]: null })
                            }
                            className="border-border bg-card hover:border-foreground/30 inline-flex items-center gap-1 rounded-full border py-1 pr-1.5 pl-2.5 text-xs transition-colors"
                        >
                            <span className="text-muted-foreground">
                                {chip.label}
                            </span>
                            <X className="size-3" />
                            <span className="sr-only">
                                Remove {chip.label} filter
                            </span>
                        </button>
                    ))}
                    {active && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 px-2 text-xs"
                            onClick={clearAll}
                        >
                            Clear all
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * The active filters as removable chips (search lives in its own box, so it isn't chipped). Each
 * chip's `key` is the `Filters` field it clears.
 */
function activeChips(
    filters: Filters,
    campaigns: CampaignOption[],
    options: Props['filterOptions'],
): { key: keyof Filters; label: string }[] {
    const label = (list: Option[], value: number | string) =>
        list.find((item) => String(item.value) === String(value))?.label ??
        String(value);

    const chips: { key: keyof Filters; label: string }[] = [];

    if (filters.client !== null) {
        chips.push({
            key: 'client',
            label: `Client: ${label(options.clients, filters.client)}`,
        });
    }

    if (filters.campaign !== null) {
        chips.push({
            key: 'campaign',
            label: `Campaign: ${label(campaigns, filters.campaign)}`,
        });
    }

    if (filters.assignee !== null) {
        chips.push({
            key: 'assignee',
            label: `Assignee: ${filters.assignee === 0 ? 'Unassigned' : label(options.staff, filters.assignee)}`,
        });
    }

    if (filters.status !== null) {
        chips.push({
            key: 'status',
            label: `Status: ${label(options.statuses, filters.status)}`,
        });
    }

    if (filters.platform !== null) {
        chips.push({
            key: 'platform',
            label: `Platform: ${label(options.platforms, filters.platform)}`,
        });
    }

    if (filters.has_media !== null) {
        chips.push({
            key: 'has_media',
            label: filters.has_media ? 'Has media' : 'No media',
        });
    }

    if (filters.from !== null) {
        chips.push({ key: 'from', label: `From ${formatDate(filters.from)}` });
    }

    if (filters.to !== null) {
        chips.push({ key: 'to', label: `To ${formatDate(filters.to)}` });
    }

    return chips;
}

function FilterSelect({
    label,
    value,
    onChange,
    options,
    triggerClassName = 'w-auto min-w-32',
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: Option[];
    triggerClassName?: string;
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger
                size="sm"
                className={triggerClassName}
                aria-label={label}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>Any {label.toLowerCase()}</SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** A labelled filter control stacked inside the Filters popover. */
function FilterField({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <span className="eyebrow text-muted-foreground">{label}</span>
            {children}
        </div>
    );
}

function SavedViews({
    views,
    filters,
    view,
    onSave,
}: {
    views: SavedView[];
    filters: Filters;
    view: string;
    onSave: () => void;
}) {
    const applyView = (saved: SavedView) =>
        visit(filters, view, { ...emptyFilters(), ...saved.filters });

    const removeView = (saved: SavedView) =>
        router.delete(destroyView(saved.id).url, {
            preserveScroll: true,
            preserveState: true,
        });

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm">
                    <Bookmark />
                    Views
                    {views.length > 0 && (
                        <span className="font-mono text-xs tabular-nums">
                            {views.length}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel>Saved views</DropdownMenuLabel>
                {views.length === 0 ? (
                    <p className="text-muted-foreground px-2 py-1.5 text-xs">
                        Save the current filters to come back to them.
                    </p>
                ) : (
                    views.map((saved) => (
                        <div key={saved.id} className="flex items-center">
                            <DropdownMenuItem
                                className="flex-1"
                                onSelect={() => applyView(saved)}
                            >
                                {saved.name}
                            </DropdownMenuItem>
                            <button
                                type="button"
                                aria-label={`Remove ${saved.name}`}
                                className="text-muted-foreground hover:text-destructive mr-1 rounded p-1"
                                onClick={() => removeView(saved)}
                            >
                                <Trash2 className="size-3.5" />
                            </button>
                        </div>
                    ))
                )}
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={onSave}>
                    <BookmarkPlus />
                    Save current view
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function emptyFilters(): Filters {
    return {
        search: null,
        client: null,
        campaign: null,
        assignee: null,
        status: null,
        platform: null,
        from: null,
        to: null,
        has_media: null,
    };
}

function BulkBar({
    ids,
    campaigns,
    statuses,
    staff,
    runBulk,
    clear,
}: {
    ids: number[];
    campaigns: CampaignOption[];
    statuses: Option[];
    staff: Option[];
    runBulk: (
        action: string,
        ids: number[],
        value: string | number | null,
        onDone?: () => void,
    ) => void;
    clear: () => void;
}) {
    const statusLabel = (value: string) =>
        statuses.find((s) => s.value === value)?.label ?? value;

    const archive = async () => {
        const ok = await confirmDialog({
            title: `Archive ${ids.length} ${ids.length === 1 ? 'post' : 'posts'}?`,
            description:
                'Archived posts drop out of every list and stop publishing. You can still find them with the Archived filter.',
            confirmLabel: 'Archive',
            tone: 'destructive',
        });

        if (ok) {
            runBulk('archive', ids, null, clear);
        }
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="spark" size="sm">
                        <ArrowRightLeft />
                        Move to
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                    {MOVE_TARGETS.map((status) => (
                        <DropdownMenuItem
                            key={status}
                            onSelect={() => runBulk('move', ids, status, clear)}
                        >
                            {statusLabel(status)}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="on-ink" size="sm">
                        <UserRound />
                        Assign
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent className="max-h-72 overflow-y-auto">
                    <DropdownMenuItem
                        onSelect={() => runBulk('assign', ids, null, clear)}
                    >
                        Unassign
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    {staff.map((member) => (
                        <DropdownMenuItem
                            key={member.value}
                            onSelect={() =>
                                runBulk(
                                    'assign',
                                    ids,
                                    Number(member.value),
                                    clear,
                                )
                            }
                        >
                            {member.label}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="on-ink" size="sm">
                        <KanbanSquare />
                        Campaign
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent className="max-h-72 overflow-y-auto">
                    <DropdownMenuItem
                        onSelect={() => runBulk('campaign', ids, null, clear)}
                    >
                        No campaign
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    {campaigns.map((campaign) => (
                        <DropdownMenuItem
                            key={campaign.value}
                            onSelect={() =>
                                runBulk(
                                    'campaign',
                                    ids,
                                    Number(campaign.value),
                                    clear,
                                )
                            }
                        >
                            {campaign.label}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="on-ink" size="sm">
                        <CalendarClock />
                        Reschedule
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                    {[-7, -1, 1, 7].map((days) => (
                        <DropdownMenuItem
                            key={days}
                            onSelect={() =>
                                runBulk('reschedule', ids, days, clear)
                            }
                        >
                            {days > 0
                                ? `Later by ${days}d`
                                : `Earlier by ${-days}d`}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>

            <Button variant="on-ink" size="sm" onClick={() => void archive()}>
                <Trash2 />
                Archive
            </Button>
        </>
    );
}

function Board({
    board,
    runBulk,
}: {
    board: { columns: BoardColumn[]; capped: boolean };
    runBulk: (
        action: string,
        ids: number[],
        value: string | number | null,
        onDone?: () => void,
    ) => void;
}) {
    const columns: KanbanColumn<BoardCard>[] = board.columns.map((column) => ({
        key: column.key,
        label: column.label,
        dropStatus: column.drop_status,
        tone: toneBgClass[resolveStatus('post', column.key).tone],
        items: column.posts,
    }));

    const move = (post: BoardCard, status: string) =>
        runBulk('move', [post.id], status);

    if (board.columns.every((column) => column.posts.length === 0)) {
        return (
            <EmptyState
                icon={KanbanSquare}
                title="An empty board."
                description="Posts show up here as they move through the workflow."
            />
        );
    }

    return (
        <div className="animate-rise flex flex-col gap-2">
            {board.capped && (
                <p className="text-muted-foreground text-xs">
                    Showing the most recent posts. Narrow the filters to see a
                    specific set.
                </p>
            )}
            <KanbanBoard
                columns={columns}
                dropTargets={(post) => post.drop_targets}
                onMove={move}
                renderCard={(post) => (
                    <article className="bg-card border-border hover:border-primary/40 flex flex-col gap-2 rounded-xl border p-3 shadow-sm transition-colors">
                        <Link
                            href={editPost(post.id)}
                            className="hover:text-primary line-clamp-3 text-sm font-medium"
                            draggable={false}
                        >
                            {post.master_caption || 'Untitled post'}
                        </Link>
                        <span className="text-muted-foreground truncate text-xs">
                            {post.client.name}
                        </span>
                        <div className="text-muted-foreground flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px]">
                            {post.targets.map((target, i) => {
                                const Icon = platformIcon(target.platform);

                                return (
                                    <Icon
                                        key={i}
                                        className="size-3.5"
                                        aria-label={
                                            target.platform ?? undefined
                                        }
                                    />
                                );
                            })}
                            <span className="font-mono">
                                {formatSchedule(
                                    post.scheduled_local_date,
                                    post.scheduled_local_time,
                                    'Unscheduled',
                                )}
                            </span>
                        </div>
                        {post.drop_targets.length > 0 && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="h-7 justify-start px-2"
                                    >
                                        <ArrowRightLeft className="size-3.5" />
                                        Move
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="start">
                                    <DropdownMenuLabel>
                                        Move to
                                    </DropdownMenuLabel>
                                    {post.drop_targets.map((status) => (
                                        <DropdownMenuItem
                                            key={status}
                                            onSelect={() => move(post, status)}
                                        >
                                            {
                                                resolveStatus('post', status)
                                                    .label
                                            }
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </article>
                )}
            />
        </div>
    );
}

ContentIndex.layout = {
    breadcrumbs: [{ title: 'Content', href: index() }],
};
