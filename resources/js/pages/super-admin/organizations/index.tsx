import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    Building2,
    ChevronLeft,
    ChevronRight,
    Plus,
    Search,
    X,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { EmptyState, Page, PageHeader, StatStrip } from '@/components/page';
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
                <PageHeader
                    eyebrow={`${counts.all} on the platform`}
                    title={
                        <>
                            Every <em>organization</em>.
                        </>
                    }
                    description="Find any workspace by its name or anyone on its team, then edit, suspend or remove it."
                    actions={
                        <Button asChild>
                            <Link href={createOrganization()}>
                                <Plus />
                                Onboard an organization
                            </Link>
                        </Button>
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
                        <>
                            <div className="border-border/80 bg-card overflow-hidden rounded-2xl border shadow-sm">
                                <div className="text-muted-foreground border-border/70 hidden grid-cols-[minmax(0,2fr)_minmax(0,1.6fr)_repeat(3,5rem)_7rem] gap-4 border-b px-6 py-3 lg:grid">
                                    <span className="eyebrow">
                                        Organization
                                    </span>
                                    <span className="eyebrow">Owner</span>
                                    <span className="eyebrow text-right">
                                        People
                                    </span>
                                    <span className="eyebrow text-right">
                                        Clients
                                    </span>
                                    <span className="eyebrow text-right">
                                        Live
                                    </span>
                                    <span className="eyebrow text-right">
                                        Created
                                    </span>
                                </div>
                                <ul className="divide-border/70 divide-y">
                                    {organizations.data.map((organization) => (
                                        <li key={organization.id}>
                                            <Link
                                                href={showOrganization(
                                                    organization.id,
                                                )}
                                                className="group hover:bg-muted/50 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 px-5 py-4 transition-colors sm:px-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1.6fr)_repeat(3,5rem)_7rem]"
                                            >
                                                <span className="flex min-w-0 items-center gap-2.5">
                                                    <span className="truncate font-medium group-hover:underline">
                                                        {organization.name}
                                                    </span>
                                                    <StatusPill
                                                        kind="organization"
                                                        value={organizationStatus(
                                                            organization,
                                                        )}
                                                        className="shrink-0"
                                                    />
                                                </span>
                                                <ArrowUpRight className="text-muted-foreground size-4 justify-self-end opacity-60 lg:hidden" />
                                                <span className="text-muted-foreground col-span-2 min-w-0 truncate text-xs lg:col-span-1 lg:text-sm">
                                                    {organization.owner ? (
                                                        <>
                                                            <span className="text-foreground">
                                                                {
                                                                    organization
                                                                        .owner
                                                                        .name
                                                                }
                                                            </span>{' '}
                                                            ·{' '}
                                                            {
                                                                organization
                                                                    .owner.email
                                                            }
                                                        </>
                                                    ) : (
                                                        'No owner'
                                                    )}
                                                </span>
                                                <span className="text-muted-foreground col-span-2 font-mono text-xs tabular-nums lg:hidden">
                                                    {organization.users_count}{' '}
                                                    people ·{' '}
                                                    {organization.clients_count}{' '}
                                                    clients ·{' '}
                                                    {
                                                        organization.published_posts_count
                                                    }{' '}
                                                    live · since{' '}
                                                    {formatDate(
                                                        organization.created_at,
                                                    )}
                                                </span>
                                                <span className="hidden text-right font-mono text-sm tabular-nums lg:block">
                                                    {organization.users_count}
                                                </span>
                                                <span className="hidden text-right font-mono text-sm tabular-nums lg:block">
                                                    {organization.clients_count}
                                                </span>
                                                <span className="hidden text-right font-mono text-sm tabular-nums lg:block">
                                                    {
                                                        organization.published_posts_count
                                                    }
                                                </span>
                                                <span className="text-muted-foreground hidden text-right font-mono text-xs lg:block">
                                                    {formatDate(
                                                        organization.created_at,
                                                    )}
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            {organizations.last_page > 1 && (
                                <nav
                                    aria-label="Pagination"
                                    className="flex items-center justify-between gap-3"
                                >
                                    <span className="text-muted-foreground font-mono text-xs tabular-nums">
                                        {organizations.from}–{organizations.to}{' '}
                                        of {organizations.total}
                                    </span>
                                    <div className="flex items-center gap-2">
                                        <PageLink
                                            href={organizations.prev_page_url}
                                            label="Previous"
                                            icon="prev"
                                        />
                                        <PageLink
                                            href={organizations.next_page_url}
                                            label="Next"
                                            icon="next"
                                        />
                                    </div>
                                </nav>
                            )}
                        </>
                    )}
                </section>
            </Page>
        </>
    );
}

function PageLink({
    href,
    label,
    icon,
}: {
    href: string | null;
    label: string;
    icon: 'prev' | 'next';
}) {
    const content = (
        <>
            {icon === 'prev' && <ChevronLeft />}
            {label}
            {icon === 'next' && <ChevronRight />}
        </>
    );

    if (!href) {
        return (
            <Button variant="outline" size="sm" disabled>
                {content}
            </Button>
        );
    }

    return (
        <Button variant="outline" size="sm" asChild>
            <Link href={href} preserveScroll preserveState>
                {content}
            </Link>
        </Button>
    );
}

OrganizationsIndex.layout = {
    breadcrumbs: [{ title: 'Organizations', href: organizationsIndex() }],
};
