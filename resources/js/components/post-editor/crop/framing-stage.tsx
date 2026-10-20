import { Crosshair } from 'lucide-react';
import type { KeyboardEvent, PointerEvent } from 'react';
import { useRef } from 'react';
import type { PreviewAsset } from '@/components/asset-preview-carousel';
import type { Crop } from '@/lib/media-format';
import { cropBox, normalizeCrop } from '@/lib/media-format';

/**
 * The draggable framing stage: the whole image, the channel's crop box over it (everything
 * outside dimmed), rule-of-thirds guides, and keyboard nudging. With no crop on the channel it
 * still sets the focus point, which the other channels use.
 */
export function FramingStage({
    asset,
    ratio,
    crop,
    aspect,
    onMeasure,
    onChange,
}: {
    asset: PreviewAsset;
    ratio: number | null;
    crop: Crop;
    aspect: number | null;
    onMeasure: (id: number, aspect: number) => void;
    onChange: (crop: Crop) => void;
}) {
    const stageRef = useRef<HTMLDivElement>(null);
    const drag = useRef<{ dx: number; dy: number } | null>(null);
    const box = ratio && aspect ? cropBox(aspect, ratio, crop) : null;

    const pointAt = (event: PointerEvent) => {
        const rect = stageRef.current?.getBoundingClientRect();

        if (!rect || rect.width === 0 || rect.height === 0) {
            return null;
        }

        return {
            x: (event.clientX - rect.left) / rect.width,
            y: (event.clientY - rect.top) / rect.height,
        };
    };

    const moveTo = (x: number, y: number) => {
        onChange(normalizeCrop({ ...crop, x, y }));
    };

    const onPointerDown = (event: PointerEvent<HTMLDivElement>) => {
        const point = pointAt(event);

        if (!point) {
            return;
        }

        event.currentTarget.setPointerCapture(event.pointerId);

        const inside =
            box &&
            point.x >= box.x &&
            point.x <= box.x + box.w &&
            point.y >= box.y &&
            point.y <= box.y + box.h;

        if (inside && box) {
            // Grab the box where it is (its effective centre), so dragging never lags behind.
            const cx = box.x + box.w / 2;
            const cy = box.y + box.h / 2;
            drag.current = { dx: point.x - cx, dy: point.y - cy };
            moveTo(cx, cy);
        } else {
            drag.current = { dx: 0, dy: 0 };
            moveTo(point.x, point.y);
        }
    };

    const onPointerMove = (event: PointerEvent<HTMLDivElement>) => {
        const point = drag.current ? pointAt(event) : null;

        if (point && drag.current) {
            moveTo(point.x - drag.current.dx, point.y - drag.current.dy);
        }
    };

    const onKeyDown = (event: KeyboardEvent) => {
        const step = event.shiftKey ? 0.05 : 0.01;
        const moves: Record<string, [number, number]> = {
            ArrowLeft: [-step, 0],
            ArrowRight: [step, 0],
            ArrowUp: [0, -step],
            ArrowDown: [0, step],
        };

        if (moves[event.key]) {
            event.preventDefault();
            const [dx, dy] = moves[event.key];
            const cx = box ? box.x + box.w / 2 : crop.x;
            const cy = box ? box.y + box.h / 2 : crop.y;
            moveTo(cx + dx, cy + dy);
        } else if (event.key === '+' || event.key === '=') {
            event.preventDefault();
            onChange(normalizeCrop({ ...crop, zoom: crop.zoom + 0.1 }));
        } else if (event.key === '-') {
            event.preventDefault();
            onChange(normalizeCrop({ ...crop, zoom: crop.zoom - 0.1 }));
        }
    };

    return (
        <div className="bg-muted/60 border-border flex min-h-56 items-center justify-center rounded-xl border p-3">
            <div
                ref={stageRef}
                role="slider"
                tabIndex={0}
                aria-label="Focus point — drag, or use the arrow keys (Shift for bigger steps, + and − to zoom)"
                aria-valuetext={`${Math.round(crop.x * 100)}% across, ${Math.round(crop.y * 100)}% down`}
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={() => (drag.current = null)}
                onPointerCancel={() => (drag.current = null)}
                onKeyDown={onKeyDown}
                className="focus-visible:ring-ring/50 relative inline-block cursor-grab touch-none overflow-hidden rounded-md outline-none select-none focus-visible:ring-[3px] active:cursor-grabbing"
            >
                <img
                    src={asset.url ?? undefined}
                    alt={asset.original_filename ?? ''}
                    draggable={false}
                    onLoad={(event) => {
                        const { naturalWidth, naturalHeight } =
                            event.currentTarget;

                        if (naturalWidth && naturalHeight) {
                            onMeasure(asset.id, naturalWidth / naturalHeight);
                        }
                    }}
                    className="block max-h-[50vh] max-w-full"
                />

                {box ? (
                    <div
                        className="border-cream pointer-events-none absolute border-2"
                        style={{
                            left: `${box.x * 100}%`,
                            top: `${box.y * 100}%`,
                            width: `${box.w * 100}%`,
                            height: `${box.h * 100}%`,
                            boxShadow:
                                '0 0 0 9999px color-mix(in srgb, var(--ink) 58%, transparent)',
                        }}
                    >
                        {[1, 2].map((n) => (
                            <span key={`v${n}`}>
                                <span
                                    className="bg-cream/45 absolute inset-y-0 w-px"
                                    style={{ left: `${(n * 100) / 3}%` }}
                                />
                                <span
                                    className="bg-cream/45 absolute inset-x-0 h-px"
                                    style={{ top: `${(n * 100) / 3}%` }}
                                />
                            </span>
                        ))}
                    </div>
                ) : (
                    <span
                        className="border-cream bg-ink/40 pointer-events-none absolute flex size-7 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 shadow-md"
                        style={{
                            left: `${crop.x * 100}%`,
                            top: `${crop.y * 100}%`,
                        }}
                    >
                        <Crosshair className="text-cream size-3.5" />
                    </span>
                )}
            </div>
        </div>
    );
}
