import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { GridTileImage } from '@/components/grids/grid-tile-image';
import type { GridSummary } from '@/components/grids/types';
import { StatusPill } from '@/components/status-pill';
import { formatDateTime } from '@/lib/dates';
import { gridDisplayStatus } from '@/lib/grid-workflow';

/**
 * A grid plan as a card: the first nine tiles as the profile shows them, its status (derived
 * "In production" / "Live" once posts exist) and progress. Used on the client's Grid tab, the
 * campaign workspace and the portal.
 */
export function GridCard({
    grid,
    href,
}: {
    grid: GridSummary;
    href: NonNullable<InertiaLinkProps['href']>;
}) {
    const status = gridDisplayStatus(grid.status, grid.progress);
    const cells = [
        ...grid.preview,
        ...Array.from(
            { length: Math.max(0, 9 - grid.preview.length) },
            () => null,
        ),
    ];

    return (
        <Link
            href={href}
            className="group border-border bg-card hover:border-primary/40 focus-visible:ring-ring/40 flex flex-col gap-3 rounded-2xl border p-3 shadow-sm transition-colors outline-none focus-visible:ring-[3px]"
        >
            <div className="bg-border/70 grid grid-cols-3 gap-px overflow-hidden rounded-xl">
                {cells.map((tile, index) =>
                    tile ? (
                        <GridTileImage
                            key={tile.id}
                            tile={{
                                kind: tile.kind,
                                placeholder_colour: tile.placeholder_colour,
                                frame: tile.frame,
                                assets: tile.thumbnail_url
                                    ? [
                                          {
                                              id: tile.id,
                                              url: tile.thumbnail_url,
                                              thumbnail_url: tile.thumbnail_url,
                                              type: 'image',
                                              width: null,
                                              height: null,
                                              original_filename: null,
                                              alt_text: null,
                                              crop: { x: 0.5, y: 0.5, zoom: 1 },
                                          },
                                      ]
                                    : [],
                            }}
                            sizes="120px"
                        />
                    ) : (
                        <div
                            key={`empty-${index}`}
                            className="bg-muted aspect-[3/4]"
                        />
                    ),
                )}
            </div>
            <div className="flex min-w-0 flex-col gap-1 px-1">
                <span className="group-hover:text-primary truncate text-sm font-semibold">
                    {grid.name}
                </span>
                <span className="text-muted-foreground flex flex-wrap items-center gap-x-2 text-xs">
                    <span>{grid.handle}</span>
                    <span aria-hidden>·</span>
                    <span>
                        {grid.tile_count}{' '}
                        {grid.tile_count === 1 ? 'tile' : 'tiles'}
                    </span>
                    {grid.progress.posts > 0 && (
                        <>
                            <span aria-hidden>·</span>
                            <span>{grid.progress.live} live</span>
                        </>
                    )}
                </span>
                <div className="flex flex-wrap items-center justify-between gap-2 pt-1">
                    <StatusPill kind="gridPlan" value={status} />
                    <span className="text-muted-foreground font-mono text-[11px]">
                        {formatDateTime(grid.updated_at)}
                    </span>
                </div>
            </div>
        </Link>
    );
}
