import { Head, router } from '@inertiajs/react';
import { AtSign, LayoutGrid } from 'lucide-react';
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
import { Button } from '@/components/ui/button';
import { index as accountsIndex } from '@/routes/business/accounts';
import { store as storeGrid } from '@/routes/business/grids';
import { edit as editGrid } from '@/routes/grids';

/**
 * Step CG-1.2 — the grid planner hub in the business/creator shell (`/grid`). The brand's Instagram
 * grid plans, with "New grid" posting to the brand-implicit `business.grids.store`. The planner
 * itself is the shared `grids.edit` surface. When the brand has no Instagram account, an empty state
 * points at Accounts (a grid needs an Instagram account).
 */
export default function BusinessGridsIndex({
    grids,
    brandId,
    accounts,
    campaignOptions,
    hasInstagram,
    can,
}: {
    grids: Paginated<GridSummary>;
    brandId: number;
    accounts: GridAccountOption[];
    campaignOptions: GridCampaignOption[];
    hasInstagram: boolean;
    can: { create: boolean };
}) {
    return (
        <>
            <Head title="Grid" />
            <Page>
                <ChapterHero
                    eyebrow={`${String(grids.total).padStart(2, '0')} grid plans`}
                    title={
                        <>
                            Plan your <em>grid</em>.
                        </>
                    }
                    description="Lay out your next run of Instagram posts, preview them on your real profile, then turn the tiles into scheduled posts — in the right order."
                    actions={
                        can.create && (
                            <NewGridDialog
                                accounts={accounts}
                                campaignOptions={campaignOptions}
                                clientId={brandId}
                                storeUrl={storeGrid.url()}
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
                            caption="Planned and published"
                        />
                    }
                />

                {!hasInstagram ? (
                    <EmptyState
                        icon={AtSign}
                        title="Connect Instagram first."
                        description="The grid planner previews your Instagram profile, so it needs a connected Instagram account."
                        action={
                            <Button variant="brand" asChild>
                                <a href={accountsIndex().url}>
                                    Connect Instagram
                                </a>
                            </Button>
                        }
                    />
                ) : grids.data.length === 0 ? (
                    <EmptyState
                        icon={LayoutGrid}
                        title="No grids yet."
                        description="Start one to lay out your next few Instagram posts and see the look before anything goes out."
                    />
                ) : (
                    <ul className="animate-rise grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {grids.data.map((grid) => (
                            <li key={grid.id}>
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
