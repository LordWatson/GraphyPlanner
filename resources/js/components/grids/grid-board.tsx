import {
    AlertTriangle,
    Clapperboard,
    Copy,
    GripVertical,
    MessageSquare,
    Pin,
    Plus,
    Scissors,
} from 'lucide-react';
import type { KeyboardEvent } from 'react';
import { useMemo, useRef, useState } from 'react';
import { GridTileImage } from '@/components/grids/grid-tile-image';
import type { GridOutsideTile, GridTileData } from '@/components/grids/types';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { GRID_COLUMNS, gridSlots, ordinal } from '@/lib/grid-layout';
import { cn } from '@/lib/utils';

type Block = { key: string; ids: number[] };

/** Tiles grouped into the units that move: one tile, or a whole split group. */
export function blocksOf(tiles: GridTileData[]): Block[] {
    const blocks: Block[] = [];

    for (const tile of tiles) {
        const last = blocks[blocks.length - 1];

        if (
            tile.split_group_id &&
            last &&
            last.key === `split-${tile.split_group_id}`
        ) {
            last.ids.push(tile.id);
        } else {
            blocks.push({
                key: tile.split_group_id
                    ? `split-${tile.split_group_id}`
                    : `tile-${tile.id}`,
                ids: [tile.id],
            });
        }
    }

    return blocks;
}

function moveBlock(blocks: Block[], from: number, to: number): Block[] {
    const next = [...blocks];
    const [moved] = next.splice(from, 1);
    next.splice(Math.max(0, Math.min(next.length, to)), 0, moved);

    return next;
}

type BoardCell =
    | { kind: 'pinned'; slot: number }
    | { kind: 'outside'; slot: number; outside: GridOutsideTile }
    | { kind: 'tile'; slot: number; tile: GridTileData };

/**
 * Step 18.5 — the planner's board: a 3-column grid of 3:4 tiles in display order, with row guides,
 * pinned posts and outside posts in their real slots (so rows read true). Drag a tile — or a
 * whole split, which moves as one block — to reorder; with the keyboard, Space lifts, the arrow
 * keys move and Space drops (Escape cancels), announced to screen readers.
 */
export function GridBoard({
    tiles,
    pinnedCount,
    outside,
    selectedTileId,
    hoveredTileIds = [],
    problemTileIds = [],
    commentCounts = {},
    readOnly,
    onSelect,
    onReorder,
    onAdd,
    onAddOutside,
}: {
    tiles: GridTileData[];
    pinnedCount: number;
    outside: GridOutsideTile[];
    selectedTileId: number | null;
    hoveredTileIds?: number[];
    problemTileIds?: number[];
    commentCounts?: Record<number, number>;
    readOnly: boolean;
    onSelect: (tileId: number) => void;
    onReorder: (orderedIds: number[]) => void;
    onAdd: (atPosition: number) => void;
    onAddOutside?: (postId: number) => void;
}) {
    const [dragging, setDragging] = useState<string | null>(null);
    const [dropKey, setDropKey] = useState<string | null>(null);
    const [lifted, setLifted] = useState<{
        key: string;
        order: Block[];
    } | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const tileRefs = useRef(new Map<number, HTMLButtonElement>());

    const blocks = useMemo(() => blocksOf(tiles), [tiles]);
    const activeBlocks = lifted?.order ?? blocks;
    const tileById = useMemo(
        () => new Map(tiles.map((tile) => [tile.id, tile])),
        [tiles],
    );
    const orderedTiles = activeBlocks
        .flatMap((block) => block.ids)
        .map((id) => tileById.get(id))
        .filter((tile): tile is GridTileData => tile !== undefined);

    // Slots from the shared maths, for the order on screen (including a lifted block).
    const layout = useMemo(
        () =>
            gridSlots(
                orderedTiles.map((tile, index) => ({
                    id: tile.id,
                    position: index + 1,
                    split_group_id: tile.split_group_id,
                    publish_at: tile.publish_at,
                })),
                pinnedCount,
                outside
                    .filter((post) => post.publish_at !== null)
                    .map((post) => ({
                        id: post.post_id,
                        publish_at: post.publish_at as string,
                    })),
            ),
        [orderedTiles, pinnedCount, outside],
    );

    const cells: BoardCell[] = [
        ...Array.from(
            { length: layout.pinned_count },
            (_, slot): BoardCell => ({ kind: 'pinned', slot }),
        ),
        ...layout.slots.flatMap((slot): BoardCell[] => {
            if (slot.kind === 'tile') {
                const tile = tileById.get(slot.id);

                return tile
                    ? [
                          {
                              kind: 'tile',
                              slot: slot.slot,
                              tile: { ...tile, sequence: slot.sequence },
                          },
                      ]
                    : [];
            }

            const post = outside.find((item) => item.post_id === slot.id);

            return post
                ? [{ kind: 'outside', slot: slot.slot, outside: post }]
                : [];
        }),
    ];
    const misaligned = new Set(
        layout.split_groups
            .filter((group) => !group.aligned)
            .flatMap((group) => group.tile_ids),
    );
    const rows = Math.ceil(cells.length / GRID_COLUMNS);

    const blockIndexOf = (tileId: number, list: Block[]) =>
        list.findIndex((block) => block.ids.includes(tileId));
    const describe = (tile: GridTileData) =>
        tile.split_group_id
            ? `The split starting at tile ${tile.position}`
            : `Tile ${tile.position}`;

    const commit = (order: Block[]) => {
        const ids = order.flatMap((block) => block.ids);

        if (ids.join(',') !== tiles.map((tile) => tile.id).join(',')) {
            onReorder(ids);
        }
    };

    const handleKeyDown = (
        event: KeyboardEvent<HTMLButtonElement>,
        tile: GridTileData,
    ) => {
        if (readOnly) {
            return;
        }

        const blockKey = blocks[blockIndexOf(tile.id, blocks)]?.key;

        if (event.key === ' ' || (event.key === 'Enter' && lifted)) {
            event.preventDefault();

            if (!lifted) {
                setLifted({ key: blockKey, order: blocks });
                setAnnouncement(
                    `${describe(tile)} lifted. Use the arrow keys to move it, space to drop, escape to cancel.`,
                );

                return;
            }

            const position =
                lifted.order.findIndex((block) => block.key === lifted.key) + 1;
            commit(lifted.order);
            setLifted(null);
            setAnnouncement(`Dropped at place ${position}.`);

            return;
        }

        if (lifted && event.key === 'Escape') {
            event.preventDefault();
            setLifted(null);
            setAnnouncement('Move cancelled.');

            return;
        }

        if (
            lifted &&
            ['ArrowLeft', 'ArrowUp', 'ArrowRight', 'ArrowDown'].includes(
                event.key,
            )
        ) {
            event.preventDefault();
            const from = lifted.order.findIndex(
                (block) => block.key === lifted.key,
            );
            const step =
                event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 1;
            const to = from + step;

            if (to < 0 || to >= lifted.order.length) {
                return;
            }

            const order = moveBlock(lifted.order, from, to);
            setLifted({ key: lifted.key, order });
            setAnnouncement(`Moved to place ${to + 1} of ${order.length}.`);
            // Keep focus on the moving tile.
            requestAnimationFrame(() => tileRefs.current.get(tile.id)?.focus());
        }
    };

    const onDrop = (targetTile: GridTileData) => {
        if (!dragging) {
            return;
        }

        const from = blocks.findIndex((block) => block.key === dragging);
        const to = blockIndexOf(targetTile.id, blocks);

        if (from >= 0 && to >= 0 && from !== to) {
            const order = moveBlock(blocks, from, to);
            commit(order);
            setAnnouncement(`Moved to place ${to + 1}.`);
        }

        setDragging(null);
        setDropKey(null);
    };

    return (
        <div className="flex flex-col gap-3">
            <div aria-live="assertive" className="sr-only">
                {announcement}
            </div>
            {!readOnly && (
                <Button
                    type="button"
                    variant="outline"
                    className="w-full border-dashed"
                    onClick={() => onAdd(1)}
                >
                    <Plus />
                    Add tiles at the top
                </Button>
            )}
            <div className="relative flex gap-2">
                <ol className="flex w-6 shrink-0 flex-col" aria-hidden>
                    {Array.from({ length: rows }, (_, row) => (
                        <li
                            key={row}
                            className="text-muted-foreground flex flex-1 items-center justify-center font-mono text-[10px]"
                        >
                            {String(row + 1).padStart(2, '0')}
                        </li>
                    ))}
                </ol>
                <ul
                    className="bg-border/70 grid min-w-0 flex-1 grid-cols-3 gap-[3px] overflow-hidden rounded-xl border"
                    aria-label="Grid tiles, top-left first"
                    aria-describedby="grid-board-help"
                >
                    {cells.map((cell) => {
                        if (cell.kind === 'pinned') {
                            return (
                                <li
                                    key={`pinned-${cell.slot}`}
                                    className="bg-muted text-muted-foreground relative flex aspect-[3/4] flex-col items-center justify-center gap-1"
                                >
                                    <Pin className="size-4" />
                                    <span className="text-[11px]">
                                        Pinned post
                                    </span>
                                </li>
                            );
                        }

                        if (cell.kind === 'outside') {
                            return (
                                <li
                                    key={`outside-${cell.outside.post_id}`}
                                    className="bg-card relative"
                                >
                                    <GridTileImage
                                        tile={{
                                            kind: 'outside',
                                            thumbnail_url:
                                                cell.outside.thumbnail_url,
                                        }}
                                        className="opacity-60"
                                    />
                                    <div className="absolute inset-x-1 bottom-1 flex flex-col items-center gap-1">
                                        <span className="bg-ink/75 text-cream rounded-sm px-1.5 py-0.5 text-[10px] font-medium">
                                            Not in this plan
                                        </span>
                                        {onAddOutside && !readOnly && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                className="h-7 text-xs"
                                                onClick={() =>
                                                    onAddOutside(
                                                        cell.outside.post_id,
                                                    )
                                                }
                                            >
                                                Add to plan
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            );
                        }

                        const tile = cell.tile;
                        const blockKey =
                            blocks[blockIndexOf(tile.id, blocks)]?.key ?? '';
                        const isLifted = lifted?.key === blockKey;
                        const imageCount = tile.assets.length;

                        return (
                            <li
                                key={tile.id}
                                className={cn(
                                    'bg-card relative',
                                    dropKey === blockKey &&
                                        dragging !== blockKey &&
                                        'ring-primary z-10 ring-2',
                                )}
                                onDragOver={(event) => {
                                    if (dragging && !readOnly) {
                                        event.preventDefault();
                                        setDropKey(blockKey);
                                    }
                                }}
                                onDragLeave={() =>
                                    setDropKey((current) =>
                                        current === blockKey ? null : current,
                                    )
                                }
                                onDrop={(event) => {
                                    event.preventDefault();
                                    onDrop(tile);
                                }}
                            >
                                <button
                                    ref={(element) => {
                                        if (element) {
                                            tileRefs.current.set(
                                                tile.id,
                                                element,
                                            );
                                        } else {
                                            tileRefs.current.delete(tile.id);
                                        }
                                    }}
                                    type="button"
                                    draggable={!readOnly}
                                    onDragStart={(event) => {
                                        event.dataTransfer.effectAllowed =
                                            'move';
                                        setDragging(blockKey);
                                    }}
                                    onDragEnd={() => {
                                        setDragging(null);
                                        setDropKey(null);
                                    }}
                                    onClick={() => onSelect(tile.id)}
                                    onKeyDown={(event) =>
                                        handleKeyDown(event, tile)
                                    }
                                    aria-pressed={selectedTileId === tile.id}
                                    aria-label={[
                                        `Tile ${tile.position}`,
                                        tile.kind_label,
                                        tile.sequence !== null
                                            ? `goes out ${ordinal(tile.sequence)}`
                                            : null,
                                        tile.split_group_id
                                            ? 'part of a split'
                                            : null,
                                        tile.post
                                            ? `post ${tile.post.status_label}`
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(', ')}
                                    className={cn(
                                        'group focus-visible:ring-ring relative block w-full text-left outline-none focus-visible:z-10 focus-visible:ring-[3px]',
                                        !readOnly &&
                                            'cursor-grab active:cursor-grabbing',
                                        selectedTileId === tile.id &&
                                            'ring-primary z-10 ring-[3px]',
                                        hoveredTileIds.includes(tile.id) &&
                                            'ring-lime z-10 ring-[3px]',
                                        isLifted &&
                                            'ring-electric z-20 scale-[0.97] ring-[3px] motion-safe:transition-transform',
                                        dragging === blockKey && 'opacity-50',
                                    )}
                                >
                                    <GridTileImage tile={tile} />
                                    {tile.split_group_id && (
                                        <span
                                            aria-hidden
                                            className={cn(
                                                'pointer-events-none absolute inset-0 border-2 border-dashed',
                                                misaligned.has(tile.id)
                                                    ? 'border-destructive'
                                                    : 'border-lime/90',
                                            )}
                                        />
                                    )}
                                    <span className="absolute inset-x-1 top-1 flex items-start justify-between gap-1">
                                        <span className="bg-ink/75 text-cream rounded-sm px-1 py-0.5 font-mono text-[10px] leading-none tabular-nums">
                                            {String(tile.position).padStart(
                                                2,
                                                '0',
                                            )}
                                        </span>
                                        <span className="flex items-center gap-1">
                                            {problemTileIds.includes(
                                                tile.id,
                                            ) && (
                                                <AlertTriangle
                                                    className="text-cream bg-destructive size-4 rounded-full p-0.5"
                                                    aria-hidden
                                                />
                                            )}
                                            {tile.drift.length > 0 && (
                                                <span className="bg-warning text-ink rounded-sm px-1 py-0.5 text-[9px] font-medium">
                                                    Changed
                                                </span>
                                            )}
                                            {tile.kind === 'carousel' && (
                                                <span className="bg-ink/75 text-cream inline-flex items-center gap-0.5 rounded-sm px-1 py-0.5 text-[10px] leading-none">
                                                    <Copy className="size-2.5" />
                                                    {imageCount}
                                                </span>
                                            )}
                                            {tile.kind === 'reel' && (
                                                <span className="bg-ink/75 text-cream inline-flex items-center gap-0.5 rounded-sm px-1 py-0.5 text-[10px] leading-none">
                                                    <Clapperboard className="size-2.5" />
                                                    Reel
                                                </span>
                                            )}
                                            {tile.split_group_id && (
                                                <Scissors
                                                    className="text-cream size-3.5 drop-shadow"
                                                    aria-hidden
                                                />
                                            )}
                                        </span>
                                    </span>
                                    <span className="absolute inset-x-1 bottom-1 flex flex-wrap items-end justify-between gap-1">
                                        {tile.sequence !== null && (
                                            <span className="bg-cream/90 text-ink rounded-sm px-1 py-0.5 text-[10px] leading-none font-medium">
                                                Goes out{' '}
                                                {ordinal(tile.sequence)}
                                            </span>
                                        )}
                                        {(commentCounts[tile.id] ?? 0) > 0 && (
                                            <span className="bg-cream/90 text-ink inline-flex items-center gap-0.5 rounded-sm px-1 py-0.5 text-[10px] leading-none">
                                                <MessageSquare className="size-2.5" />
                                                {commentCounts[tile.id]}
                                            </span>
                                        )}
                                    </span>
                                    {!readOnly && (
                                        <GripVertical
                                            aria-hidden
                                            className="text-cream absolute top-1/2 right-0.5 size-4 -translate-y-1/2 opacity-0 drop-shadow transition-opacity group-hover:opacity-80 group-focus-visible:opacity-80"
                                        />
                                    )}
                                </button>
                                {tile.post && (
                                    <span className="pointer-events-none absolute right-1 bottom-6 origin-bottom-right scale-[0.8]">
                                        <StatusPill
                                            kind="post"
                                            value={tile.post.status}
                                            label={tile.post.status_label}
                                        />
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </div>
            <p id="grid-board-help" className="text-muted-foreground text-xs">
                {readOnly
                    ? 'Read only.'
                    : 'Drag tiles to reorder, or select one and press space, then the arrow keys. Split images move together.'}
            </p>
        </div>
    );
}
