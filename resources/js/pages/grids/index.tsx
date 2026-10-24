import { Head, router } from '@inertiajs/react';
import { LayoutGrid } from 'lucide-react';
import { GridCard } from '@/components/grids/grid-card';
import type {
    GridAccountOption,
    GridCampaignOption,
} from '@/components/grids/new-grid-dialog';
import { NewGridDialog } from '@/components/grids/new-grid-dialog';
import type { GridSummary } from '@/components/grids/types';
import { ChapterHero, EmptyState, HeroStat, Page } from '@/components/page';
import type { Paginated } from '@/components/pagination';
import { Pagination } from '@/components/pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index as clientsIndex } from '@/routes/clients';
import { edit as editGrid, index as gridsIndex } from '@/routes/grids';

type Option = { value: string; label: string };

/**
 * Phase 18 — the grids hub (`GET /grids`): every client's Instagram grid plans in one place,
 * filterable by client and status, with "New grid" for any client that has an Instagram account.
 * A section of chapter 04 Clients, like the campaigns hub.
 */
export default function GridsIndex({
    grids,
    filters,
    filterOptions,
    accounts,
    campaignOptions,
    can,
}: {
    grids: Paginated<GridSummary & { client: { id: number; name: string } }>;
    filters: { client_id: number | null; status: string | null };
    filterOptions: { clients: Option[]; statuses: Option[] };
    accounts: GridAccountOption[];
    campaignOptions: GridCampaignOption[];
    can: { create: boolean };
}) {
    const applyFilter = (key: 'client_id' | 'status', value: string) =>
        router.get(
            gridsIndex.url(),
            { ...filters, [key]: value === 'all' ? null : value },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title="Grids" />
            <Page>
                <ChapterHero
                    eyebrow={`${String(grids.total).padStart(2, '0')} grid plans`}
                    title={
                        <>
                            Plan the <em>grid</em>.
                        </>
                    }
                    description="Every client’s Instagram grid plans: lay out the next run of posts, preview them on the real profile, and get the look signed off."
                    actions={
                        can.create && (
                            <NewGridDialog
                                accounts={accounts}
                                campaignOptions={campaignOptions}
                                triggerVariant="spark"
                                triggerSize="lg"
                            />
                        )
                    }
                    aside={
                        <HeroStat
                            icon={LayoutGrid}
                            eyebrow="Grid plans"
                            value={String(grids.total).padStart(2, '0')}
                            caption="Across every client"
                        />
                    }
                />

                <div className="border-border flex flex-wrap items-center gap-2 border-y py-3">
                    <span className="eyebrow text-muted-foreground mr-1">
                        Filter
                    </span>
                    <Select
                        value={
                            filters.client_id
                                ? String(filters.client_id)
                                : 'all'
                        }
                        onValueChange={(value) =>
                            applyFilter('client_id', value)
                        }
                    >
                        <SelectTrigger
                            size="sm"
                            className="w-44"
                            aria-label="Client"
                        >
                            <SelectValue placeholder="Client" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All clients</SelectItem>
                            {filterOptions.clients.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.status ?? 'all'}
                        onValueChange={(value) => applyFilter('status', value)}
                    >
                        <SelectTrigger
                            size="sm"
                            className="w-44"
                            aria-label="Status"
                        >
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            {filterOptions.statuses.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {grids.data.length === 0 ? (
                    <EmptyState
                        icon={LayoutGrid}
                        title="No grids yet."
                        description={
                            can.create
                                ? 'Start one to lay out a client’s next few Instagram posts and get the look signed off.'
                                : 'A grid needs a client with an Instagram account. Add one on a client’s Social accounts tab first.'
                        }
                    />
                ) : (
                    <ul className="animate-rise grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {grids.data.map((grid) => (
                            <li key={grid.id} className="flex flex-col gap-1.5">
                                <span className="eyebrow text-muted-foreground px-1">
                                    {grid.client.name}
                                </span>
                                <GridCard
                                    grid={grid}
                                    href={editGrid(grid.id)}
                                />
                            </li>
                        ))}
                    </ul>
                )}

                <Pagination
                    page={grids}
                    label="grids"
                    onPageChange={(next) =>
                        router.reload({
                            data: { page: next },
                            only: ['grids'],
                        })
                    }
                />
            </Page>
        </>
    );
}

GridsIndex.layout = {
    breadcrumbs: [
        { title: 'Clients', href: clientsIndex() },
        { title: 'Grids', href: gridsIndex() },
    ],
};
