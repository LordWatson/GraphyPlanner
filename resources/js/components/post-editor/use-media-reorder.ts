import type { KeyboardEvent } from 'react';
import { useRef, useState } from 'react';
import type { AssetData } from '@/components/post-editor/types';

function moveItem(list: AssetData[], from: number, to: number): AssetData[] {
    const next = [...list];
    const [moved] = next.splice(from, 1);
    next.splice(Math.max(0, Math.min(next.length, to)), 0, moved);

    return next;
}

const orderOf = (assets: AssetData[]) => assets.map((a) => a.id).join(',');

export const nameOf = (asset: AssetData) =>
    asset.original_filename ?? 'this item';

/**
 * Reordering the post's attached media — the whole interaction, both ways in:
 *
 * - mouse: drag one tile onto another (`dragIndex`/`overIndex` drive the drop target's ring);
 * - keyboard: focus a tile's grip handle and press space to lift it, the arrow keys to move it,
 *   space to drop, escape to cancel.
 *
 * A keyboard drag stages its order in `lifted` until dropped, so escape can cancel cleanly and
 * never writes a half-finished order back. Every move sets `announcement` for the live region.
 * `onChange` only fires when the order actually changed.
 */
export function useMediaReorder(
    selected: AssetData[],
    onChange: (assets: AssetData[]) => void,
) {
    const [dragIndex, setDragIndex] = useState<number | null>(null);
    const [overIndex, setOverIndex] = useState<number | null>(null);
    const [lifted, setLifted] = useState<{
        id: number;
        order: AssetData[];
    } | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const handleRefs = useRef(new Map<number, HTMLButtonElement>());

    const commit = (next: AssetData[]) => {
        if (orderOf(next) !== orderOf(selected)) {
            onChange(next);
        }
    };

    const onDrop = (to: number) => {
        if (dragIndex === null) {
            return;
        }

        if (dragIndex !== to) {
            commit(moveItem(selected, dragIndex, to));
            setAnnouncement(
                `Moved to position ${to + 1} of ${selected.length}.`,
            );
        }

        setDragIndex(null);
        setOverIndex(null);
    };

    const handleKeyDown = (
        event: KeyboardEvent<HTMLButtonElement>,
        asset: AssetData,
    ) => {
        if (event.key === ' ' || event.key === 'Enter') {
            event.preventDefault();

            if (!lifted) {
                setLifted({ id: asset.id, order: selected });
                setAnnouncement(
                    `${nameOf(asset)} lifted. Use the arrow keys to move it, space to drop, escape to cancel.`,
                );

                return;
            }

            const position =
                lifted.order.findIndex((a) => a.id === lifted.id) + 1;
            commit(lifted.order);
            setLifted(null);
            setAnnouncement(`Dropped at position ${position}.`);

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
            ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(
                event.key,
            )
        ) {
            event.preventDefault();
            const from = lifted.order.findIndex((a) => a.id === lifted.id);
            const step =
                event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 1;
            const to = from + step;

            if (to < 0 || to >= lifted.order.length) {
                return;
            }

            const nextOrder = moveItem(lifted.order, from, to);
            setLifted({ id: lifted.id, order: nextOrder });
            setAnnouncement(
                `Moved to position ${to + 1} of ${nextOrder.length}.`,
            );
            requestAnimationFrame(() =>
                handleRefs.current.get(asset.id)?.focus(),
            );
        }
    };

    const setHandleRef = (id: number, element: HTMLButtonElement | null) => {
        if (element) {
            handleRefs.current.set(id, element);
        } else {
            handleRefs.current.delete(id);
        }
    };

    return {
        // While a keyboard drag is in flight the staged order is shown; otherwise the saved one.
        order: lifted?.order ?? selected,
        announcement,
        reorderable: selected.length > 1,
        dragIndex,
        overIndex,
        lifted,
        setDragIndex,
        setOverIndex,
        onDrop,
        handleKeyDown,
        setHandleRef,
    };
}
