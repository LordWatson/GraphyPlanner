import { Head } from '@inertiajs/react';
import { LayoutGrid } from 'lucide-react';
import { GridCard } from '@/components/grids/grid-card';
import type { GridSummary } from '@/components/grids/types';
import { ChapterHero, EmptyState, HeroStat, Page } from '@/components/page';
import { show as portalGridsShow } from '@/routes/portal/grids';

/**
 * Step 18.8 — the client's grids in the portal: each one as it'll look on their profile, the ones
 * waiting on them first. Only grids the studio has sent are listed.
 */
export default function PortalGridsIndex({ grids }: { grids: GridSummary[] }) {
    const waiting = grids.filter(
        (grid) => grid.status === 'waiting_client',
    ).length;

    return (
        <>
            <Head title="Grids — Client portal" />
            <Page>
                <ChapterHero
                    eyebrow={
                        waiting > 0
                            ? `Grids — ${waiting} waiting on you`
                            : 'Grids'
                    }
                    title={
                        <>
                            Your Instagram <em>grid</em>.
                        </>
                    }
                    description="How your profile will look over the next run of posts. Approve the look here; each caption comes to you separately."
                    aside={
                        <HeroStat
                            icon={LayoutGrid}
                            eyebrow={waiting > 0 ? 'Waiting on you' : 'Grids'}
                            value={String(
                                waiting > 0 ? waiting : grids.length,
                            ).padStart(2, '0')}
                            caption={
                                waiting > 0
                                    ? waiting === 1
                                        ? 'Grid to approve'
                                        : 'Grids to approve'
                                    : grids.length === 1
                                      ? 'Grid'
                                      : 'Grids'
                            }
                        />
                    }
                />
                {grids.length === 0 ? (
                    <EmptyState
                        icon={LayoutGrid}
                        title="Nothing to see yet."
                        description="Grids appear here once the studio sends one over."
                    />
                ) : (
                    <ul className="animate-rise grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {grids.map((grid) => (
                            <li key={grid.id}>
                                <GridCard
                                    grid={grid}
                                    href={portalGridsShow(grid.id)}
                                />
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
        </>
    );
}
