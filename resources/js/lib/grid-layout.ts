import type { CSSProperties } from 'react';
import { cropBox, normalizeCrop, ratioQuotient } from '@/lib/media-format';
import type { Crop, CropRect } from '@/lib/media-format';

/**
 * Phase 18 — the Instagram grid planner's layout core, the frontend mirror of
 * `App\Services\Grids\GridLayoutService` (slots, rows, publish sequence, split alignment) and
 * `App\Support\Grids\GridTileFrame` (what each tile shows on the 3:4 grid). Both sides run the
 * shared fixture `tests/Fixtures/grid-layout.json`, so keep them in step.
 *
 * Position 1 is top-left and newest; the grid publishes in reverse.
 */

/** Tiles per profile grid row. */
export const GRID_COLUMNS = 3;

/** Instagram pins at most three posts in front of the grid. */
export const MAX_PINNED = 3;

/** The profile grid's tile shape: 3:4 portrait. */
export const GRID_RATIO = 3 / 4;

/** Instagram's feed range for an `original` image (registry `instagram.formats.feed.aspect`). */
const FEED_RANGE: [number, number] = [0.8, 1.91];

const HIDDEN_TOLERANCE = 0.005;

export type GridLayoutTileInput = {
    id: number;
    position: number;
    split_group_id: string | null;
    /** ISO-8601 UTC, when known. */
    publish_at: string | null;
};

export type GridOutsideInput = { id: number; publish_at: string };

export type GridSlot = {
    kind: 'tile' | 'outside';
    id: number;
    slot: number;
    row: number;
    column: number;
    sequence: number | null;
};

export type GridSplitGroup = {
    id: string;
    tile_ids: number[];
    first_slot: number;
    rows: number[];
    aligned: boolean;
    offset: number;
};

export type GridLayoutResult = {
    pinned_count: number;
    slots: GridSlot[];
    split_groups: GridSplitGroup[];
};

function time(value: string): number {
    return new Date(value).getTime();
}

/**
 * `GridLayoutService::layout()`: every tile's slot (0-based, pinned posts first), row, column and
 * publish sequence, with outside posts placed in front of the first tile they're newer than.
 */
export function gridSlots(
    tiles: GridLayoutTileInput[],
    pinnedCount = 0,
    outside: GridOutsideInput[] = [],
): GridLayoutResult {
    const pinned = Math.max(0, Math.min(MAX_PINNED, pinnedCount));
    const ordered = [...tiles].sort((a, b) => a.position - b.position);
    const queue = [...outside].sort(
        (a, b) => time(b.publish_at) - time(a.publish_at) || a.id - b.id,
    );

    const entries: { kind: 'tile' | 'outside'; id: number }[] = [];

    for (const tile of ordered) {
        if (tile.publish_at !== null) {
            const at = time(tile.publish_at);

            while (queue.length > 0 && time(queue[0].publish_at) > at) {
                entries.push({ kind: 'outside', id: queue.shift()!.id });
            }
        }

        entries.push({ kind: 'tile', id: tile.id });
    }

    for (const post of queue) {
        entries.push({ kind: 'outside', id: post.id });
    }

    const sequence = new Map<number, number>();
    ordered.forEach((tile, index) => {
        sequence.set(tile.id, ordered.length - index);
    });

    const slots: GridSlot[] = entries.map((entry, index) => {
        const slot = pinned + index;

        return {
            kind: entry.kind,
            id: entry.id,
            slot,
            row: Math.floor(slot / GRID_COLUMNS) + 1,
            column: (slot % GRID_COLUMNS) + 1,
            sequence:
                entry.kind === 'tile' ? (sequence.get(entry.id) ?? null) : null,
        };
    });

    return {
        pinned_count: pinned,
        slots,
        split_groups: splitGroups(ordered, slots),
    };
}

function splitGroups(
    tiles: GridLayoutTileInput[],
    slots: GridSlot[],
): GridSplitGroup[] {
    const slotOf = new Map<number, number>();

    for (const slot of slots) {
        if (slot.kind === 'tile') {
            slotOf.set(slot.id, slot.slot);
        }
    }

    const groups = new Map<string, number[]>();

    for (const tile of tiles) {
        if (tile.split_group_id !== null) {
            groups.set(tile.split_group_id, [
                ...(groups.get(tile.split_group_id) ?? []),
                tile.id,
            ]);
        }
    }

    return [...groups.entries()].map(([id, tileIds]) => {
        const groupSlots = tileIds.map((tileId) => slotOf.get(tileId) ?? 0);
        const first = Math.min(...groupSlots);
        const last = Math.max(...groupSlots);
        const size = tileIds.length;

        return {
            id,
            tile_ids: tileIds,
            first_slot: first,
            rows: [
                ...new Set(
                    groupSlots.map(
                        (slot) => Math.floor(slot / GRID_COLUMNS) + 1,
                    ),
                ),
            ],
            aligned:
                last - first + 1 === size &&
                size % GRID_COLUMNS === 0 &&
                first % GRID_COLUMNS === 0,
            offset: first % GRID_COLUMNS,
        };
    });
}

export type GridTileFrameResult = {
    /** The published frame, as fractions of the image. */
    frame: CropRect;
    /** The part of the image the 3:4 grid tile shows. */
    grid: CropRect;
    cropped_on_grid: boolean;
    /** Share of the published frame the grid hides (0–1). */
    hidden_share: number;
};

function round4(value: number): number {
    return Math.round(value * 10000) / 10000;
}

function roundRect(rect: CropRect): CropRect {
    return {
        x: round4(rect.x),
        y: round4(rect.y),
        w: round4(rect.w),
        h: round4(rect.h),
    };
}

/**
 * `GridTileFrame::compute()`: the tile's first image framed at its format (exactly as the
 * publisher cuts it), then centre-cropped to the 3:4 grid tile.
 */
export function gridTileFrame(
    width: number | null,
    height: number | null,
    format: string,
    crop: Partial<Crop> | null = null,
): GridTileFrameResult {
    const quotient = ratioQuotient(format);
    const aspect =
        width && height && width > 0 && height > 0
            ? width / height
            : (quotient ?? 1);
    const ratio =
        quotient ?? Math.min(FEED_RANGE[1], Math.max(FEED_RANGE[0], aspect));
    const frame = cropBox(aspect, ratio, normalizeCrop(crop));

    const frameAspect = (frame.w * aspect) / frame.h;
    const grid = { ...frame };

    if (frameAspect > GRID_RATIO) {
        grid.w = frame.w * (GRID_RATIO / frameAspect);
        grid.x = frame.x + (frame.w - grid.w) / 2;
    } else if (frameAspect < GRID_RATIO) {
        grid.h = frame.h * (frameAspect / GRID_RATIO);
        grid.y = frame.y + (frame.h - grid.h) / 2;
    }

    const hidden = Math.max(0, 1 - (grid.w * grid.h) / (frame.w * frame.h));

    return {
        frame: roundRect(frame),
        grid: roundRect(grid),
        cropped_on_grid: hidden > HIDDEN_TOLERANCE,
        hidden_share: round4(hidden),
    };
}

/**
 * CSS for an `<img>` inside a box so only `rect` (fractions of the image) shows, filling the box.
 */
export function rectImageStyle(rect: CropRect): CSSProperties {
    return {
        position: 'absolute',
        width: `${100 / rect.w}%`,
        height: `${100 / rect.h}%`,
        left: `${(-rect.x / rect.w) * 100}%`,
        top: `${(-rect.y / rect.h) * 100}%`,
        maxWidth: 'none',
        objectFit: 'fill',
    };
}

/** "1st", "2nd", "3rd", "4th"… for "Goes out 3rd". */
export function ordinal(value: number): string {
    const tens = value % 100;

    if (tens >= 11 && tens <= 13) {
        return `${value}th`;
    }

    switch (value % 10) {
        case 1:
            return `${value}st`;
        case 2:
            return `${value}nd`;
        case 3:
            return `${value}rd`;
        default:
            return `${value}th`;
    }
}

/**
 * Whether a tile has gone out by `asOf` (the preview's "as of" scrubber): undated tiles never
 * show as published.
 */
export function isOutBy(publishAt: string | null, asOf: Date): boolean {
    return publishAt !== null && time(publishAt) <= asOf.getTime();
}

/**
 * A publish moment (UTC ISO-8601) on the account's own clock, the way `formatSchedule()` writes a
 * channel slot: "Sat 17 Oct · 09:00".
 */
export function formatGridMoment(
    iso: string | null,
    timezone: string,
    fallback = 'No date yet',
): string {
    if (!iso) {
        return fallback;
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return fallback;
    }

    const options = { timeZone: timezone } as const;
    const day = date.toLocaleDateString('en-GB', {
        ...options,
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
    const time = date.toLocaleTimeString('en-GB', {
        ...options,
        hour: '2-digit',
        minute: '2-digit',
    });

    return `${day} · ${time}`;
}
