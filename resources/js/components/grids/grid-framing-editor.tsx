import { useRef } from 'react';
import type { GridAsset } from '@/components/grids/types';
import { Label } from '@/components/ui/label';
import { gridTileFrame } from '@/lib/grid-layout';
import type { Crop } from '@/lib/media-format';
import { MAX_ZOOM, MIN_ZOOM, normalizeCrop } from '@/lib/media-format';

const pct = (value: number) => `${(value * 100).toFixed(3)}%`;

/**
 * Step 18.5 — focus point and zoom for one image, with a live overlay: the solid outline is the
 * frame the post publishes with (the same `CropBox` maths the publisher uses), the dashed lime box
 * is what the 3:4 profile grid shows of it, and everything outside the frame is dimmed. Drag (or
 * use the arrow keys) to move the focus point.
 */
export function GridFramingEditor({
    asset,
    format,
    crop,
    onChange,
    disabled = false,
}: {
    asset: GridAsset;
    format: string;
    crop: Crop;
    onChange: (crop: Crop) => void;
    disabled?: boolean;
}) {
    const boxRef = useRef<HTMLDivElement>(null);
    const src = asset.url ?? asset.thumbnail_url;
    const aspect = asset.width && asset.height ? asset.width / asset.height : 1;
    const frame = gridTileFrame(asset.width, asset.height, format, crop);

    const moveTo = (clientX: number, clientY: number) => {
        const box = boxRef.current?.getBoundingClientRect();

        if (!box || disabled) {
            return;
        }

        onChange(
            normalizeCrop({
                ...crop,
                x: (clientX - box.left) / box.width,
                y: (clientY - box.top) / box.height,
            }),
        );
    };

    if (!src) {
        return null;
    }

    return (
        <div className="grid gap-3">
            <div
                ref={boxRef}
                role="slider"
                tabIndex={disabled ? -1 : 0}
                aria-label="Focus point — drag, or use the arrow keys"
                aria-valuetext={`${Math.round(crop.x * 100)}% across, ${Math.round(crop.y * 100)}% down`}
                aria-disabled={disabled}
                className="focus-visible:ring-ring relative mx-auto max-h-80 w-full touch-none overflow-hidden rounded-lg outline-none select-none focus-visible:ring-[3px]"
                style={{
                    aspectRatio: aspect,
                    maxWidth: `calc(20rem * ${aspect})`,
                }}
                onPointerDown={(event) => {
                    event.currentTarget.setPointerCapture(event.pointerId);
                    moveTo(event.clientX, event.clientY);
                }}
                onPointerMove={(event) => {
                    if (
                        event.currentTarget.hasPointerCapture(event.pointerId)
                    ) {
                        moveTo(event.clientX, event.clientY);
                    }
                }}
                onKeyDown={(event) => {
                    const step = event.shiftKey ? 0.1 : 0.02;
                    const delta: Record<string, [number, number]> = {
                        ArrowLeft: [-step, 0],
                        ArrowRight: [step, 0],
                        ArrowUp: [0, -step],
                        ArrowDown: [0, step],
                    };
                    const move = delta[event.key];

                    if (move && !disabled) {
                        event.preventDefault();
                        onChange(
                            normalizeCrop({
                                ...crop,
                                x: crop.x + move[0],
                                y: crop.y + move[1],
                            }),
                        );
                    }
                }}
            >
                <img
                    src={src}
                    alt={asset.alt_text ?? ''}
                    draggable={false}
                    className="absolute inset-0 size-full object-cover"
                />
                <span
                    aria-hidden
                    className="bg-ink/55 absolute inset-x-0 top-0"
                    style={{ height: pct(frame.frame.y) }}
                />
                <span
                    aria-hidden
                    className="bg-ink/55 absolute inset-x-0 bottom-0"
                    style={{ top: pct(frame.frame.y + frame.frame.h) }}
                />
                <span
                    aria-hidden
                    className="bg-ink/55 absolute left-0"
                    style={{
                        top: pct(frame.frame.y),
                        height: pct(frame.frame.h),
                        width: pct(frame.frame.x),
                    }}
                />
                <span
                    aria-hidden
                    className="bg-ink/55 absolute right-0"
                    style={{
                        top: pct(frame.frame.y),
                        height: pct(frame.frame.h),
                        left: pct(frame.frame.x + frame.frame.w),
                    }}
                />
                <span
                    aria-hidden
                    className="border-cream absolute border-2"
                    style={{
                        left: pct(frame.frame.x),
                        top: pct(frame.frame.y),
                        width: pct(frame.frame.w),
                        height: pct(frame.frame.h),
                    }}
                />
                <span
                    aria-hidden
                    className="border-lime absolute border-2 border-dashed"
                    style={{
                        left: pct(frame.grid.x),
                        top: pct(frame.grid.y),
                        width: pct(frame.grid.w),
                        height: pct(frame.grid.h),
                    }}
                />
                <span
                    aria-hidden
                    className="border-cream bg-ink/40 absolute size-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2"
                    style={{ left: pct(crop.x), top: pct(crop.y) }}
                />
            </div>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                <span className="inline-flex items-center gap-1.5">
                    <span
                        aria-hidden
                        className="border-cream bg-ink size-3 rounded-sm border-2"
                    />
                    Published frame
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <span
                        aria-hidden
                        className="border-lime size-3 rounded-sm border-2 border-dashed"
                    />
                    Shown on the grid
                </span>
                <span className="text-muted-foreground ml-auto font-mono">
                    {frame.cropped_on_grid
                        ? `Grid hides ${Math.max(1, Math.round(frame.hidden_share * 100))}%`
                        : 'Shows in full'}
                </span>
            </div>
            <div className="grid gap-1.5">
                <Label
                    htmlFor={`zoom-${asset.id}`}
                    className="flex justify-between text-xs"
                >
                    Zoom
                    <span className="text-muted-foreground font-mono">
                        {crop.zoom.toFixed(2)}×
                    </span>
                </Label>
                <input
                    id={`zoom-${asset.id}`}
                    type="range"
                    min={MIN_ZOOM}
                    max={MAX_ZOOM}
                    step={0.05}
                    value={crop.zoom}
                    disabled={disabled}
                    onChange={(event) =>
                        onChange(
                            normalizeCrop({
                                ...crop,
                                zoom: Number(event.target.value),
                            }),
                        )
                    }
                    className="accent-primary w-full"
                />
            </div>
        </div>
    );
}
