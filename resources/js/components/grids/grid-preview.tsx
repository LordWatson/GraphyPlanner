import {
    ChevronLeft,
    ChevronRight,
    Clapperboard,
    Copy,
    Crop,
    Grid3x3,
    LayoutGrid,
    Moon,
    Pin,
    Smartphone,
    Spline,
    Sun,
    Tag,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
import type { PreviewAsset } from '@/components/asset-preview-carousel';
import { GridTileImage } from '@/components/grids/grid-tile-image';
import type {
    ClientGridPlan,
    ClientGridTile,
    GridOutsideTile,
    ProfileFeed,
    ProfileFeedItem,
} from '@/components/grids/types';
import { SocialPostPreview } from '@/components/social-post-preview';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    formatGridMoment,
    GRID_COLUMNS,
    gridSlots,
    isOutBy,
    ordinal,
} from '@/lib/grid-layout';
import { cn } from '@/lib/utils';

type Cell =
    | { kind: 'tile'; key: string; tile: ClientGridTile }
    | { kind: 'outside'; key: string; outside: GridOutsideTile }
    | { kind: 'pinned'; key: string; item: ProfileFeedItem | null }
    | { kind: 'feed'; key: string; item: ProfileFeedItem };

const PHONE_WIDTH = { small: 'w-[300px]', large: 'w-[380px]' } as const;

/** Tile kind → the Instagram target format its preview renders as. */
function targetFormatOf(tile: ClientGridTile): string | null {
    return tile.kind === 'reel'
        ? 'reel'
        : tile.kind === 'carousel'
          ? 'carousel'
          : 'feed';
}

function asPreviewAsset(asset: ClientGridTile['assets'][number]): PreviewAsset {
    return {
        id: asset.id,
        url: asset.url,
        original_filename: asset.original_filename,
        type: asset.type,
        width: asset.width,
        height: asset.height,
        crop: asset.crop,
    };
}

/** Distinct publish moments of the plan, oldest first — the as-of scrubber's stops. */
function scrubStops(tiles: ClientGridTile[]): string[] {
    return [
        ...new Set(
            tiles
                .map((tile) => tile.publish_at)
                .filter((value): value is string => value !== null),
        ),
    ].sort();
}

/**
 * Step 18.6 — how the client's Instagram profile will look: one component reused by the
 * planner, the review link and the portal, so everyone sees the same thing.
 *
 * The phone draws the profile header and the Posts / Reels / Tagged tabs as static chrome, then
 * the 3-column 3:4 grid: pinned posts first, the planned tiles (with any outside posts in their
 * real slots, marked "Not in this plan"), then the live feed, dimmed, under "Already on the
 * profile". Tiles use the same `GridTileFrame` maths as the server at every size.
 */
export function GridPreview({
    grid,
    feed,
    feedLoading = false,
    selectedTileId = null,
    highlightTileIds = [],
    onSelectTile,
    className,
    footer,
}: {
    grid: ClientGridPlan;
    feed?: ProfileFeed | null;
    feedLoading?: boolean;
    selectedTileId?: number | null;
    highlightTileIds?: number[];
    /** The planner selects the tile (and opens its inspector) instead of the lightbox. */
    onSelectTile?: (tileId: number) => void;
    className?: string;
    footer?: ReactNode;
}) {
    const [theme, setTheme] = useState<'light' | 'dark'>('light');
    const [device, setDevice] = useState<'small' | 'large'>('large');
    const [planOnly, setPlanOnly] = useState(false);
    const [cropTruth, setCropTruth] = useState(false);
    const [seams, setSeams] = useState(false);
    const stops = useMemo(() => scrubStops(grid.tiles), [grid.tiles]);
    // The last stop is "Everything planned" (undated tiles included).
    const [stop, setStop] = useState<number>(stops.length);
    const [lightbox, setLightbox] = useState<number | null>(null);

    const asOf = stop < stops.length ? new Date(stops[stop]) : null;

    const cells = useMemo<Cell[]>(() => {
        const visibleTiles = asOf
            ? grid.tiles.filter((tile) => isOutBy(tile.publish_at, asOf))
            : grid.tiles;
        const visibleOutside = asOf
            ? grid.outside.filter((post) => isOutBy(post.publish_at, asOf))
            : grid.outside;

        // Re-lay the visible part with the shared maths, so the as-of view is exact.
        const layout = gridSlots(
            visibleTiles.map((tile) => ({
                id: tile.id,
                position: tile.position,
                split_group_id: tile.split_group_id,
                publish_at: tile.publish_at,
            })),
            grid.pinned_count,
            visibleOutside
                .filter((post) => post.publish_at !== null)
                .map((post) => ({
                    id: post.post_id,
                    publish_at: post.publish_at as string,
                })),
        );
        const tileById = new Map(grid.tiles.map((tile) => [tile.id, tile]));
        const outsideById = new Map(
            grid.outside.map((post) => [post.post_id, post]),
        );
        const feedItems = planOnly ? [] : (feed?.items ?? []);

        const pinned: Cell[] = Array.from(
            { length: layout.pinned_count },
            (_, index) => ({
                kind: 'pinned',
                key: `pinned-${index}`,
                item: feedItems[index] ?? null,
            }),
        );
        const planned: Cell[] = layout.slots.flatMap((slot): Cell[] => {
            if (slot.kind === 'tile') {
                const tile = tileById.get(slot.id);

                return tile
                    ? [{ kind: 'tile', key: `tile-${tile.id}`, tile }]
                    : [];
            }

            const outside = outsideById.get(slot.id);

            return outside
                ? [{ kind: 'outside', key: `outside-${slot.id}`, outside }]
                : [];
        });
        const live: Cell[] = feedItems
            .slice(layout.pinned_count)
            .map((item) => ({ kind: 'feed', key: `feed-${item.id}`, item }));

        return [...pinned, ...planned, ...live];
    }, [grid, feed, planOnly, asOf]);

    const lastPlannedIndex = cells.reduce(
        (last, cell, index) =>
            cell.kind === 'tile' || cell.kind === 'outside' ? index : last,
        -1,
    );
    const rows: Cell[][] = [];

    for (let index = 0; index < cells.length; index += GRID_COLUMNS) {
        rows.push(cells.slice(index, index + GRID_COLUMNS));
    }

    const dividerAfterRow =
        lastPlannedIndex >= 0 && cells.length > lastPlannedIndex + 1
            ? Math.floor(lastPlannedIndex / GRID_COLUMNS)
            : -1;

    const tilesInOrder = cells
        .filter(
            (cell): cell is Extract<Cell, { kind: 'tile' }> =>
                cell.kind === 'tile',
        )
        .map((cell) => cell.tile);
    const lightboxIndex = tilesInOrder.findIndex(
        (tile) => tile.id === lightbox,
    );
    const lightboxTile =
        lightboxIndex >= 0 ? tilesInOrder[lightboxIndex] : null;

    const openTile = (tile: ClientGridTile) =>
        onSelectTile ? onSelectTile(tile.id) : setLightbox(tile.id);

    const ink = theme === 'dark';
    const postCount =
        (feed?.media_count ?? feed?.items.length ?? 0) + grid.tiles.length;
    const username = feed?.username || grid.account.handle.replace(/^@/, '');

    return (
        <div className={cn('flex flex-col items-center gap-4', className)}>
            <div className="flex w-full flex-wrap items-center justify-center gap-2">
                <ToggleGroup
                    type="multiple"
                    variant="outline"
                    size="sm"
                    value={[
                        ...(planOnly ? ['plan'] : []),
                        ...(cropTruth ? ['crop'] : []),
                        ...(seams ? ['seams'] : []),
                    ]}
                    onValueChange={(values) => {
                        setPlanOnly(values.includes('plan'));
                        setCropTruth(values.includes('crop'));
                        setSeams(values.includes('seams'));
                    }}
                    aria-label="Preview options"
                >
                    <ToggleGroupItem value="plan" aria-label="Plan only">
                        <LayoutGrid />
                        Plan only
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        value="crop"
                        aria-label="Show what the grid hides"
                    >
                        <Crop />
                        Crop truth
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        value="seams"
                        aria-label="Show split seams"
                    >
                        <Spline />
                        Seams
                    </ToggleGroupItem>
                </ToggleGroup>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    size="sm"
                    value={theme}
                    onValueChange={(value) =>
                        value && setTheme(value as 'light' | 'dark')
                    }
                    aria-label="Instagram theme"
                >
                    <ToggleGroupItem
                        value="light"
                        aria-label="Instagram light theme"
                    >
                        <Sun />
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        value="dark"
                        aria-label="Instagram dark theme"
                    >
                        <Moon />
                    </ToggleGroupItem>
                </ToggleGroup>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    size="sm"
                    value={device}
                    onValueChange={(value) =>
                        value && setDevice(value as 'small' | 'large')
                    }
                    aria-label="Phone size"
                >
                    <ToggleGroupItem value="small" aria-label="Small phone">
                        <Smartphone className="size-3.5" />
                    </ToggleGroupItem>
                    <ToggleGroupItem value="large" aria-label="Large phone">
                        <Smartphone />
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            {stops.length > 0 && (
                <div className="flex w-full max-w-sm flex-col gap-1.5">
                    <label
                        htmlFor={`grid-asof-${grid.id}`}
                        className="flex items-center justify-between gap-2 text-xs"
                    >
                        <span className="eyebrow text-muted-foreground">
                            As of
                        </span>
                        <span className="font-mono">
                            {asOf
                                ? formatGridMoment(
                                      stops[stop],
                                      grid.account.timezone,
                                  )
                                : 'Everything planned'}
                        </span>
                    </label>
                    <input
                        id={`grid-asof-${grid.id}`}
                        type="range"
                        min={0}
                        max={stops.length}
                        step={1}
                        value={stop}
                        onChange={(event) =>
                            setStop(Number(event.target.value))
                        }
                        className="accent-primary w-full"
                        aria-valuetext={
                            asOf
                                ? `As of ${asOf.toLocaleString()}`
                                : 'Everything planned'
                        }
                    />
                    <span className="text-muted-foreground text-[11px]">
                        Drag to watch the grid build up, one post at a time.
                    </span>
                </div>
            )}

            <div
                className={cn(
                    'border-ink/80 rounded-[2.2rem] border-[6px] p-0 shadow-md motion-safe:transition-[width] motion-safe:duration-300',
                    PHONE_WIDTH[device],
                    ink ? 'bg-ink text-cream' : 'bg-cream text-ink',
                )}
                data-theme-instagram={theme}
            >
                <div className="flex justify-center pt-2 pb-1">
                    <span
                        aria-hidden
                        className="h-1 w-14 rounded-full bg-current/25"
                    />
                </div>
                <div className="flex items-center gap-4 px-4 pt-2 pb-3">
                    <span className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-full bg-current/10 ring-2 ring-current/15">
                        {feed?.profile_picture_url ? (
                            <img
                                src={feed.profile_picture_url}
                                alt=""
                                className="size-full object-cover"
                            />
                        ) : (
                            <span className="font-display text-xl uppercase">
                                {username.charAt(0) || '·'}
                            </span>
                        )}
                    </span>
                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                        <span className="truncate text-sm font-semibold">
                            {username}
                        </span>
                        <div className="flex gap-4 text-xs">
                            <span>
                                <strong className="font-semibold tabular-nums">
                                    {postCount}
                                </strong>{' '}
                                posts
                            </span>
                            <span className="opacity-70">
                                <strong className="font-semibold tabular-nums">
                                    {grid.tiles.length}
                                </strong>{' '}
                                planned
                            </span>
                        </div>
                        {grid.account.display_name && (
                            <span className="truncate text-xs opacity-75">
                                {grid.account.display_name}
                            </span>
                        )}
                    </div>
                </div>
                <div className="flex border-t border-current/15">
                    {[Grid3x3, Clapperboard, Tag].map((Icon, index) => (
                        <span
                            key={index}
                            aria-hidden
                            className={cn(
                                'flex flex-1 justify-center py-2',
                                index === 0
                                    ? 'border-b-2 border-current'
                                    : 'opacity-45',
                            )}
                        >
                            <Icon className="size-4" />
                        </span>
                    ))}
                </div>

                <div
                    className="max-h-[560px] overflow-y-auto pb-3"
                    role="list"
                    aria-label="Profile grid"
                >
                    {feedLoading && grid.tiles.length === 0 ? (
                        <div className="grid grid-cols-3 gap-px">
                            {Array.from({ length: 6 }, (_, index) => (
                                <Skeleton
                                    key={index}
                                    className="aspect-[3/4] rounded-none"
                                />
                            ))}
                        </div>
                    ) : (
                        rows.map((row, rowIndex) => (
                            <div key={rowIndex}>
                                <div className="grid grid-cols-3 gap-px">
                                    {row.map((cell) => (
                                        <GridCell
                                            key={cell.key}
                                            cell={cell}
                                            cropTruth={cropTruth}
                                            seams={seams}
                                            selected={
                                                cell.kind === 'tile' &&
                                                cell.tile.id === selectedTileId
                                            }
                                            highlighted={
                                                cell.kind === 'tile' &&
                                                highlightTileIds.includes(
                                                    cell.tile.id,
                                                )
                                            }
                                            onOpen={openTile}
                                        />
                                    ))}
                                </div>
                                {rowIndex === dividerAfterRow && (
                                    <div className="flex items-center gap-2 px-3 py-2 opacity-70">
                                        <span className="h-px flex-1 bg-current/20" />
                                        <span className="font-mono text-[10px] tracking-[0.08em] uppercase">
                                            Already on the profile
                                        </span>
                                        <span className="h-px flex-1 bg-current/20" />
                                    </div>
                                )}
                            </div>
                        ))
                    )}
                    {cells.length === 0 && !feedLoading && (
                        <p className="px-4 py-10 text-center text-xs opacity-70">
                            Nothing to show for this date yet.
                        </p>
                    )}
                </div>
            </div>

            {feed && feed.source === 'app' && (
                <p className="text-muted-foreground max-w-sm text-center text-xs text-pretty">
                    Showing posts sent from Alima. Connect Instagram to see your
                    whole profile.
                </p>
            )}
            {footer}

            <Dialog
                open={lightboxTile !== null}
                onOpenChange={(open) => !open && setLightbox(null)}
            >
                <DialogContent
                    className="max-h-[92vh] overflow-y-auto sm:max-w-md"
                    onKeyDown={(event) => {
                        if (
                            event.key === 'ArrowRight' &&
                            lightboxIndex < tilesInOrder.length - 1
                        ) {
                            setLightbox(tilesInOrder[lightboxIndex + 1].id);
                        }

                        if (event.key === 'ArrowLeft' && lightboxIndex > 0) {
                            setLightbox(tilesInOrder[lightboxIndex - 1].id);
                        }
                    }}
                >
                    {lightboxTile && (
                        <div className="flex flex-col gap-4">
                            <div className="flex flex-col gap-1 pr-8">
                                <DialogTitle>
                                    Tile {lightboxTile.position}
                                    {lightboxTile.sequence !== null && (
                                        <span className="text-muted-foreground font-normal">
                                            {' '}
                                            · goes out{' '}
                                            {ordinal(lightboxTile.sequence)}
                                        </span>
                                    )}
                                </DialogTitle>
                                <DialogDescription>
                                    {formatGridMoment(
                                        lightboxTile.publish_at,
                                        grid.account.timezone,
                                        'No date yet',
                                    )}
                                    . Use ← and → to move between tiles.
                                </DialogDescription>
                            </div>
                            {lightboxTile.kind === 'placeholder' ? (
                                <GridTileImage
                                    tile={lightboxTile}
                                    className="rounded-xl"
                                />
                            ) : (
                                <SocialPostPreview
                                    target={{
                                        id: lightboxTile.id,
                                        platform: 'instagram',
                                        handle: grid.account.handle,
                                        display_name: grid.account.display_name,
                                        format: targetFormatOf(lightboxTile),
                                        publishedCaption:
                                            lightboxTile.caption ?? '',
                                    }}
                                    caption={lightboxTile.caption ?? ''}
                                    hashtags={[]}
                                    assets={lightboxTile.assets.map(
                                        asPreviewAsset,
                                    )}
                                    mediaFormat={{
                                        instagram:
                                            lightboxTile.kind === 'reel'
                                                ? 'original'
                                                : lightboxTile.format,
                                    }}
                                />
                            )}
                            <div className="flex justify-between">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={lightboxIndex <= 0}
                                    onClick={() =>
                                        setLightbox(
                                            tilesInOrder[lightboxIndex - 1].id,
                                        )
                                    }
                                >
                                    <ChevronLeft />
                                    Previous
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        lightboxIndex >= tilesInOrder.length - 1
                                    }
                                    onClick={() =>
                                        setLightbox(
                                            tilesInOrder[lightboxIndex + 1].id,
                                        )
                                    }
                                >
                                    Next
                                    <ChevronRight />
                                </Button>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}

function GridCell({
    cell,
    cropTruth,
    seams,
    selected,
    highlighted,
    onOpen,
}: {
    cell: Cell;
    cropTruth: boolean;
    seams: boolean;
    selected: boolean;
    highlighted: boolean;
    onOpen: (tile: ClientGridTile) => void;
}) {
    if (cell.kind === 'pinned') {
        return (
            <div role="listitem" className="relative">
                {cell.item ? (
                    <GridTileImage
                        tile={{
                            kind: 'feed',
                            thumbnail_url: cell.item.thumbnail_url,
                            media_type: cell.item.media_type,
                        }}
                    />
                ) : (
                    <div className="aspect-[3/4] w-full bg-current/10" />
                )}
                <span className="bg-ink/70 text-cream absolute top-1 right-1 inline-flex items-center gap-0.5 rounded-sm px-1 py-0.5 text-[9px] font-medium">
                    <Pin className="size-2.5" />
                    Pinned
                </span>
            </div>
        );
    }

    if (cell.kind === 'feed') {
        return (
            <div role="listitem" className="opacity-55">
                <GridTileImage
                    tile={{
                        kind: 'feed',
                        thumbnail_url: cell.item.thumbnail_url,
                        media_type: cell.item.media_type,
                    }}
                />
            </div>
        );
    }

    if (cell.kind === 'outside') {
        return (
            <div role="listitem" className="relative">
                <GridTileImage
                    tile={{
                        kind: 'outside',
                        thumbnail_url: cell.outside.thumbnail_url ?? null,
                    }}
                    className="opacity-70"
                />
                <span className="bg-ink/75 text-cream absolute inset-x-1 bottom-1 truncate rounded-sm px-1 py-0.5 text-center text-[9px] font-medium">
                    Not in this plan
                </span>
            </div>
        );
    }

    const tile = cell.tile;

    return (
        <button
            type="button"
            role="listitem"
            onClick={() => onOpen(tile)}
            aria-label={`Tile ${tile.position}, ${tile.kind_label}${tile.sequence !== null ? `, goes out ${ordinal(tile.sequence)}` : ''}`}
            className={cn(
                'focus-visible:ring-ring relative block w-full outline-none focus-visible:z-10 focus-visible:ring-[3px]',
                selected && 'ring-primary z-10 ring-[3px]',
                highlighted && !selected && 'ring-lime z-10 ring-[3px]',
                seams &&
                    tile.split_group_id &&
                    'after:border-lime after:pointer-events-none after:absolute after:inset-0 after:border after:border-dashed',
            )}
        >
            <GridTileImage tile={tile} cropTruth={cropTruth} />
            {tile.kind === 'carousel' && (
                <Copy
                    aria-hidden
                    className="text-cream absolute top-1.5 right-1.5 size-3.5 drop-shadow"
                />
            )}
            {tile.kind === 'reel' && (
                <Clapperboard
                    aria-hidden
                    className="text-cream absolute top-1.5 right-1.5 size-3.5 drop-shadow"
                />
            )}
        </button>
    );
}
