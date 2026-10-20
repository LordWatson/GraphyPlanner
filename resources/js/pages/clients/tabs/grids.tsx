import { Link, router } from '@inertiajs/react';
import { Instagram, LayoutGrid } from 'lucide-react';
import type { ClientPageProps } from '@/components/clients/client-layout';
import {
    ClientLayout,
    TabHeading,
    TabPanel,
} from '@/components/clients/client-layout';
import { GridCard } from '@/components/grids/grid-card';
import type {
    GridAccountOption,
    GridCampaignOption,
} from '@/components/grids/new-grid-dialog';
import { NewGridDialog } from '@/components/grids/new-grid-dialog';
import type { GridSummary } from '@/components/grids/types';
import { EmptyState } from '@/components/page';
import type { Paginated } from '@/components/pagination';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { index, show } from '@/routes/clients';
import { edit as editGrid } from '@/routes/grids';

/**
 * Phase 18 — the client's Grid tab (`clients.grids.index`): every Instagram grid plan as a card
 * showing the grid as it'll look, and "New grid". A grid needs one of the client's Instagram
 * accounts, so without one the tab says so and points to Social accounts.
 */
export default function ClientGridsTab({
    grids,
    accounts,
    campaignOptions,
    ...page
}: ClientPageProps & {
    grids: Paginated<GridSummary>;
    accounts: GridAccountOption[];
    campaignOptions: GridCampaignOption[];
}) {
    const { client, can } = page;
    const noInstagram = accounts.length === 0;

    return (
        <ClientLayout {...page}>
            <TabPanel>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <TabHeading
                        icon={LayoutGrid}
                        title="Plan the grid."
                        description="See the next run of Instagram posts on the real profile before anything goes out."
                    />
                    {can.createGrid && !noInstagram && (
                        <NewGridDialog
                            clientId={client.id}
                            accounts={accounts}
                            campaignOptions={campaignOptions}
                        />
                    )}
                </div>

                {noInstagram ? (
                    <EmptyState
                        compact
                        icon={Instagram}
                        title="Add an Instagram account first."
                        description={`A grid is planned for one of ${client.name}’s Instagram accounts. Add one on the Social accounts tab, then come back here.`}
                        action={
                            can.viewSocialAccounts && (
                                <Button asChild variant="outline">
                                    <Link href={show(client.id)}>
                                        Go to Social accounts
                                    </Link>
                                </Button>
                            )
                        }
                    />
                ) : grids.data.length === 0 ? (
                    <EmptyState
                        compact
                        icon={LayoutGrid}
                        title="No grids yet."
                        description={
                            can.createGrid
                                ? 'Start one to lay out the next few posts and get the look signed off.'
                                : 'Grids appear here once the studio team starts one.'
                        }
                    />
                ) : (
                    <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
            </TabPanel>
            <Pagination
                page={grids}
                label="grids"
                onPageChange={(next) =>
                    router.reload({ data: { page: next }, only: ['grids'] })
                }
            />
        </ClientLayout>
    );
}

ClientGridsTab.layout = {
    breadcrumbs: [{ title: 'Clients', href: index() }],
};
