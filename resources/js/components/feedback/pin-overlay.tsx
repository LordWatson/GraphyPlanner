import { useRef } from 'react';
import { cn } from '@/lib/utils';
import type { FeedbackThread } from './types';

/**
 * Step M-6.4 — an image with its feedback pins laid over it. In placing mode, clicking anywhere on
 * the image reports the click as x/y fractions (0–1) so a pin can be dropped at that exact spot.
 * Clicking an existing pin selects its thread.
 */
export function PinOverlay({
    url,
    pins,
    placing,
    selectedId,
    onPlace,
    onSelect,
}: {
    url: string;
    pins: Array<{ thread: FeedbackThread; index: number }>;
    placing: boolean;
    selectedId: number | null;
    onPlace: (x: number, y: number) => void;
    onSelect: (id: number) => void;
}) {
    const ref = useRef<HTMLDivElement>(null);

    const handleClick = (event: React.MouseEvent<HTMLDivElement>) => {
        if (!placing || !ref.current) {
            return;
        }
        const rect = ref.current.getBoundingClientRect();
        const x = Math.min(
            1,
            Math.max(0, (event.clientX - rect.left) / rect.width),
        );
        const y = Math.min(
            1,
            Math.max(0, (event.clientY - rect.top) / rect.height),
        );
        onPlace(x, y);
    };

    return (
        <div
            ref={ref}
            onClick={handleClick}
            className={cn(
                'relative overflow-hidden rounded-xl border',
                placing
                    ? 'ring-info/40 cursor-crosshair ring-2'
                    : 'border-border',
            )}
        >
            <img
                src={url}
                alt=""
                className="block w-full select-none"
                draggable={false}
            />
            {pins.map(({ thread, index }) => {
                const x = (thread.anchor?.x ?? 0) * 100;
                const y = (thread.anchor?.y ?? 0) * 100;
                return (
                    <button
                        key={thread.id}
                        type="button"
                        onClick={(e) => {
                            e.stopPropagation();
                            onSelect(thread.id);
                        }}
                        style={{ left: `${x}%`, top: `${y}%` }}
                        className={cn(
                            'absolute flex size-6 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-white text-xs font-semibold shadow-md transition-transform hover:scale-110',
                            thread.resolved
                                ? 'bg-success text-success-foreground'
                                : selectedId === thread.id
                                  ? 'bg-ink text-ink-foreground'
                                  : 'bg-info text-info-foreground',
                        )}
                        aria-label={`Pinned comment ${index}`}
                    >
                        {thread.resolved ? '✓' : index}
                    </button>
                );
            })}
        </div>
    );
}
