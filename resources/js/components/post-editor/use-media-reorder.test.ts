import { act, renderHook } from '@testing-library/react';
import type { KeyboardEvent } from 'react';
import { describe, expect, it, vi } from 'vite-plus/test';
import type { AssetData } from '@/components/post-editor/types';
import { useMediaReorder } from '@/components/post-editor/use-media-reorder';

const asset = (id: number): AssetData => ({
    id,
    url: null,
    original_filename: `file-${id}.jpg`,
    type: 'image/jpeg',
});

const ids = (assets: AssetData[]) => assets.map((a) => a.id);

/** A key press on the grip handle — only the two fields the hook reads. */
const press = (key: string) =>
    ({ key, preventDefault: () => {} }) as KeyboardEvent<HTMLButtonElement>;

/**
 * The keyboard half of reordering attached media: an order is staged while lifted and only
 * written back on an explicit drop, so escape leaves the saved order untouched. Pinned here
 * because the interaction is invisible to a mouse-driven smoke test.
 */
describe('useMediaReorder', () => {
    const selected = [asset(1), asset(2), asset(3)];

    it('commits the staged order when the lift is dropped', () => {
        const onChange = vi.fn();
        const { result } = renderHook(() =>
            useMediaReorder(selected, onChange),
        );

        act(() => result.current.handleKeyDown(press(' '), selected[0]));
        act(() =>
            result.current.handleKeyDown(press('ArrowRight'), selected[0]),
        );

        // Still staged: the move is visible but nothing has been saved yet.
        expect(ids(result.current.order)).toEqual([2, 1, 3]);
        expect(onChange).not.toHaveBeenCalled();

        act(() => result.current.handleKeyDown(press(' '), selected[0]));

        expect(onChange).toHaveBeenCalledTimes(1);
        expect(ids(onChange.mock.calls[0][0])).toEqual([2, 1, 3]);
    });

    it('throws the staged order away on escape', () => {
        const onChange = vi.fn();
        const { result } = renderHook(() =>
            useMediaReorder(selected, onChange),
        );

        act(() => result.current.handleKeyDown(press(' '), selected[0]));
        act(() =>
            result.current.handleKeyDown(press('ArrowRight'), selected[0]),
        );
        act(() => result.current.handleKeyDown(press('Escape'), selected[0]));

        expect(onChange).not.toHaveBeenCalled();
        expect(ids(result.current.order)).toEqual([1, 2, 3]);
    });

    it('will not move an item past either end', () => {
        const onChange = vi.fn();
        const { result } = renderHook(() =>
            useMediaReorder(selected, onChange),
        );

        act(() => result.current.handleKeyDown(press(' '), selected[0]));
        act(() =>
            result.current.handleKeyDown(press('ArrowLeft'), selected[0]),
        );
        act(() => result.current.handleKeyDown(press(' '), selected[0]));

        // Dropped where it started, so there is nothing to save.
        expect(onChange).not.toHaveBeenCalled();
    });

    it('ignores a mouse drop back onto the same tile', () => {
        const onChange = vi.fn();
        const { result } = renderHook(() =>
            useMediaReorder(selected, onChange),
        );

        act(() => result.current.setDragIndex(1));
        act(() => result.current.onDrop(1));

        expect(onChange).not.toHaveBeenCalled();
    });

    it('is only reorderable with more than one asset', () => {
        const { result } = renderHook(() =>
            useMediaReorder([asset(1)], vi.fn()),
        );

        expect(result.current.reorderable).toBe(false);
    });
});
