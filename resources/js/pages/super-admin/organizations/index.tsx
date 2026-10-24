import { Head, Link, router } from '@inertiajs/react';
import { Building2, Plus, Search, X } from 'lucide-react';
import type { ColumnDef } from '@tanstack/react-table';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { DataTable } from '@/components/data-table';
import {
    ChapterHero,
    EmptyState,
    HeroStat,
    Page,
    StatStrip,
} from '@/components/page';
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
import { cn } from '@/lib/utils';
import {
    create as createOrganization,
    index as organizationsIndex,
    show as showOrganization,
} from '@/routes/super-admin/organizations';

type OrganizationRow = {
    id: number;
    name: string;
    slug: string;
    owner: { name: string; email: string } | null;
    users_count: number;
    clients_count: number;
    published_posts_count: number;
    created_at: string | null;
    is_suspended: boolean;
    is_preview: boolean;
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    from: number | null;
    to: number | null;
    total: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Filters = {
    search: string;
    status: 'all' | 'active' | 'suspended';
    sort: 'newest' | 'oldest' | 'name' | 'clients' | 'published';
};

const statusTabs: { value: Filters['status']; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'suspended', label: 'Suspended' },
];

const sortOptions: { value: Filters['sort']; label: string }[] = [
    { value: 'newest', label: 'Newest first' },
    { value: 'oldest', label: 'Oldest first' },
    { value: 'name', label: 'Name A–Z' },
    { value: 'clients', label: 'Most clients' },
    { value: 'published', label: 'Most published' },
];

function organizationStatus(organization: OrganizationRow): string {
    if (organization.is_suspended) {
        return 'suspended';
    }

    return organization.is_preview ? 'preview' : 'active';
}

/** Step M-1.4: ruled rows on the shared `DataTable`; sorting stays on the preset sort menu. */
const organizationColumns: ColumnDef<OrganizationRow, unknown>[] = [
    {
        id: 'name',
        header: 'Organization',
        enableHiding: false,
        cell: ({ row }) => (
            <span className="flex min-w-0 items-center gap-2.5">
                <Link
                    href={showOrganization(row.original.id)}
                    className="truncate font-medium hover:underline"
                >
                    {row.original.name}
                </Link>
                <StatusPill
                    kind="organization"
                    value={organizationStatus(row.original)}
                    className="shrink-0"
                />
            </span>
        ),
    },
    {
        id: 'owner',
        header: 'Owner',
        meta: {
            label: 'Owner',
            hideBelow: 'md',
            className: 'text-muted-foreground max-w-72 truncate text-sm',
        },
        cell: ({ row }) =>
            row.original.owner ? (
                <>
                    <span className="text-foreground">
                        {row.original.owner.name}
                    </span>{' '}
                    · {row.original.owner.email}
                </>
            ) : (
                'No owner'
            ),
    },
    {
        id: 'people',
        header: 'People',
        meta: {
            label: 'People',
            hideBelow: 'lg',
            align: 'right',
            className: 'font-mono',
        },
        cell: ({ row }) => row.original.users_count,
    },
    {
        id: 'clients',
        header: 'Clients',
        meta: {
            label: 'Clients',
            hideBelow: 'sm',
            align: 'right',
            className: 'font-mono',
        },
        cell: ({ row }) => row.original.clients_count,
    },
    {
        id: 'live',
        header: 'Live',
        meta: {
            label: 'Published posts',
            hideBelow: 'lg',
            align: 'right',
            className: 'font-mono',
        },
        cell: ({ row }) => row.original.published_posts_count,
    },
    {
        id: 'created',
        header: 'Created',
        meta: {
            label: 'Created',
            hideBelow: 'md',
            align: 'right',
            className: 'text-muted-foreground font-mono text-xs',
        },
        cell: ({ row }) => formatDate(row.original.created_at),
    },
];

/**
 * Step 15.3 — every organization on the platform: search by org or any member, filter by
 * status, sort, page. Ruled rows (calm data screen); each row opens the detail page.
 */
export default function OrganizationsIndex({
    organizations,
    filters,
    counts,
}: {
    organizations: Paginated<OrganizationRow>;
    filters: Filters;
    counts: { all: number; active: number; suspended: number };
}) {
    const [search, setSearch] = useState(filters.search);

    const applyFilters = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(
            Object.entries(merged).filter(
                ([key, value]) =>
                    value !== '' &&
                    !(key === 'status' && value === 'all') &&
                    !(key === 'sort' && value === 'newest'),
            ),
        );

        router.get(organizationsIndex.url({ query }), undefined, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        applyFilters({ search: search.trim() });
    };

    const hasFilters =
        filters.search !== '' ||
        filters.status !== 'all' ||
        filters.sort !== 'newest';

    return (
        <>
            <Head title="Organizations" />
            <Page>
                <ChapterHero
                    eyebrow={`${counts.all} on the platform`}
                    title={
                        <>
                            Every <em>organization</em>.
                        </>
                    }
                    description="Find any workspace by its name or anyone on its team, then edit, suspend or remove it."
                    actions={
                        <Button variant="spark" size="lg" asChild>
                            <Link href={createOrganization()}>
                                <Plus />
                                Onboard an organization
                            </Link>
                        </Button>
                    }
                    aside={
                        <HeroStat
                            icon={Building2}
                            eyebrow="On the platform"
                            value={String(counts.all).padStart(2, '0')}
                            caption={`${counts.active} active · ${counts.suspended} suspended`}
                        />
                    }
                />

                <StatStrip
                    className="animate-rise"
                    items={[
                        {
                            label: 'All',
                            value: String(counts.all).padStart(2, '0'),
                            caption: 'Including the preview fixture',
                        },
                        {
                            label: 'Active',
                            value: String(counts.active).padStart(2, '0'),
                            caption: 'Members can sign in',
                        },
                        {
                            label: 'Suspended',
                            value: String(counts.suspended).padStart(2, '0'),
                            caption: 'Locked out, nothing deleted',
                        },
                    ]}
                />

                <section className="flex flex-col gap-4">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div
                            role="tablist"
                            aria-label="Filter by status"
                            className="bg-muted flex max-w-full gap-1 self-start overflow-x-auto rounded-full p-1"
                        >
                            {statusTabs.map((tab) => {
                                const active = filters.status === tab.value;

                                return (
                                    <button
                                        key={tab.value}
                                        type="button"
                                        role="tab"
                                        aria-selected={active}
                                        onClick={() =>
                                            applyFilters({ status: tab.value })
                                        }
                                        className={cn(
                                            'focus-visible:ring-ring/40 shrink-0 rounded-full px-3.5 py-1 text-[13px] font-medium whitespace-nowrap transition-colors outline-none focus-visible:ring-[3px]',
                                            active
                                                ? 'bg-primary text-primary-foreground'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {tab.label}
                                    </button>
                                );
                            })}
                        </div>

                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <form
                                onSubmit={submitSearch}
                                className="relative"
                                role="search"
                            >
                                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                                <Input
                                    type="search"
                                    aria-label="Search organizations"
                                    placeholder="Name, slug, member or email…"
                                    className="w-full pl-9 sm:w-72"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                            </form>
                            <Select
                                value={filters.sort}
                                onValueChange={(value) =>
                                    applyFilters({
                                        sort: value as Filters['sort'],
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="w-full sm:w-44"
                                    aria-label="Sort organizations"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {sortOptions.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {hasFilters && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        setSearch('');
                                        router.get(
                                            organizationsIndex.url(),
                                            undefined,
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    <X />
                                    Clear
                                </Button>
                            )}
                        </div>
                    </div>

                    {organizations.data.length === 0 ? (
                        <EmptyState
                            compact={hasFilters}
                            icon={Building2}
                            title={
                                hasFilters
                                    ? 'Nothing matches.'
                                    : 'No organizations yet.'
                            }
                            description={
                                hasFilters
                                    ? 'Try another name, email or status.'
                                    : 'Share the signup link, or onboard the first one yourself.'
                            }
                            action={
                                !hasFilters && (
                                    <Button asChild>
                                        <Link href={createOrganization()}>
                                            <Plus />
                                            Onboard an organization
                                        </Link>
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        <DataTable
                            caption="Organizations"
                            columns={organizationColumns}
                            data={organizations.data}
                            getRowId={(organization) => String(organization.id)}
                            rowHref={(organization) =>
                                showOrganization.url(organization.id)
                            }
                            storageKey="super-admin-organizations"
                            pagination={organizations}
                            paginationLabel="organizations"
                            onPageChange={(page) =>
                                router.get(
                                    organizationsIndex.url({
                                        query: {
                                            ...Object.fromEntries(
                                                Object.entries(filters).filter(
                                                    ([, value]) => value !== '',
                                                ),
                                            ),
                                            page,
                                        },
                                    }),
                                    undefined,
                                    {
                                        preserveState: true,
                                        preserveScroll: true,
                                    },
                                )
                            }
                        />
                    )}
                </section>
            </Page>
        </>
    );
}

OrganizationsIndex.layout = {
    breadcrumbs: [{ title: 'Organizations', href: organizationsIndex() }],
};
