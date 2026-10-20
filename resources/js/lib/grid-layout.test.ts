import { describe, expect, it } from 'vite-plus/test';
import fixture from '../../../tests/Fixtures/grid-layout.json';
import { gridSlots, gridTileFrame, isOutBy, ordinal } from '@/lib/grid-layout';
import type {
    GridLayoutResult,
    GridLayoutTileInput,
    GridOutsideInput,
    GridTileFrameResult,
} from '@/lib/grid-layout';

/**
 * Step 18.2 — the TS half of the shared PHP/TS fixture: `gridSlots()` and `gridTileFrame()` must
 * reproduce exactly what `GridLayoutService` / `GridTileFrame` computed (see
 * `tests/Unit/Services/Grids/GridLayoutServiceTest.php`).
 */
type LayoutCase = {
    name: string;
    pinned: number;
    tiles: GridLayoutTileInput[];
    outside: GridOutsideInput[];
    expected: GridLayoutResult;
};

type FrameCase = {
    name: string;
    width: number | null;
    height: number | null;
    format: string;
    crop: { x: number; y: number; zoom: number };
    expected: GridTileFrameResult;
};

const layouts = fixture.layouts as LayoutCase[];
const frames = fixture.frames as FrameCase[];

describe('gridSlots', () => {
    it.each(layouts.map((c) => [c.name, c] as const))('%s', (_name, c) => {
        expect(gridSlots(c.tiles, c.pinned, c.outside)).toEqual(c.expected);
    });
});

describe('gridTileFrame', () => {
    it.each(frames.map((c) => [c.name, c] as const))('%s', (_name, c) => {
        const result = gridTileFrame(c.width, c.height, c.format, c.crop);

        expect(result.cropped_on_grid).toBe(c.expected.cropped_on_grid);
        expect(result.hidden_share).toBeCloseTo(c.expected.hidden_share, 4);

        for (const key of ['frame', 'grid'] as const) {
            for (const edge of ['x', 'y', 'w', 'h'] as const) {
                expect(result[key][edge]).toBeCloseTo(c.expected[key][edge], 4);
            }
        }
    });
});

describe('helpers', () => {
    it('writes ordinals for the publish sequence', () => {
        expect([1, 2, 3, 4, 11, 12, 13, 21, 22].map(ordinal)).toEqual([
            '1st',
            '2nd',
            '3rd',
            '4th',
            '11th',
            '12th',
            '13th',
            '21st',
            '22nd',
        ]);
    });

    it('hides undated and later tiles from the as-of view', () => {
        const asOf = new Date('2026-11-05T12:00:00Z');

        expect(isOutBy('2026-11-05T09:00:00Z', asOf)).toBe(true);
        expect(isOutBy('2026-11-06T09:00:00Z', asOf)).toBe(false);
        expect(isOutBy(null, asOf)).toBe(false);
    });
});
