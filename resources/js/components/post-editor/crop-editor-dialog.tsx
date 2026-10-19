import { Crosshair, RotateCcw } from 'lucide-react';
import type { KeyboardEvent, PointerEvent } from 'react';
import { useRef, useState } from 'react';
import type { PreviewAsset } from '@/components/asset-preview-carousel';
import { platformIcon } from '@/components/platform-icon';
import {
    cropFor,
    FramedMedia,
    isImageAsset,
    useMediaAspects,
} from '@/components/social-preview/media';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { Crop, MediaFormat, MediaFormatOptions } from '@/lib/media-format';
import {
    cropBox,
    DEFAULT_CROP,
    describeRatio,
    frameFor,
    MAX_ZOOM,
    MIN_ZOOM,
    normalizeCrop,
} from '@/lib/media-format';
import { cn } from '@/lib/utils';

const platformNames: Record<string, string> = {
    instagram: 'Instagram',
    tiktok: 'TikTok',
    facebook: 'Facebook',
    linkedin: 'LinkedIn',
};

export function FormatPicker({
    platform,
    value,
    options,
    onChange,
    className,
}: {
    platform: string;
    value: string;
    options: MediaFormatOptions;
    onChange: (value: string) => void;
    className?: string;
}) {
    const choices = options[platform] ?? [];

    if (choices.length < 2) {
        return null;
    }

    return (
        <ToggleGroup
            type="single"
            variant="outline"
            size="sm"
            value={value}
            onValueChange={(next) => next && onChange(next)}
            aria-label={`${platformNames[platform] ?? platform} image format`}
            className={cn('max-w-full overflow-x-auto', className)}
        >
            {choices.map((choice) => (
                <ToggleGroupItem
                    key={choice.value}
                    value={choice.value}
                    title={choice.label}
                    className="px-2.5 font-mono text-[11px] whitespace-nowrap"
                >
                    {choice.value === 'original' ? 'Original' : choice.value}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}

/**
 * The draggable framing stage: the whole image, the channel's crop box over it (everything
 * outside dimmed), rule-of-thirds guides, and keyboard nudging. With no crop on the channel it
 * still sets the focus point, which the other channels use.
 */
function FramingStage({
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

function DialogBody({
    images,
    initialCrops,
    platforms,
    initialPlatform,
    initialFormat,
    formatOptions,
    onApply,
    onCancel,
}: {
    images: PreviewAsset[];
    initialCrops: Record<number, Crop>;
    platforms: string[];
    initialPlatform: string;
    initialFormat: MediaFormat;
    formatOptions: MediaFormatOptions;
    onApply: (crops: Record<number, Crop>, format: MediaFormat) => void;
    onCancel: () => void;
}) {
    const [platform, setPlatform] = useState(initialPlatform);
    const [format, setFormat] = useState<MediaFormat>(initialFormat);
    const [crops, setCrops] = useState<Record<number, Crop>>(() =>
        Object.fromEntries(
            images.map((image) => [image.id, cropFor(image, initialCrops)]),
        ),
    );
    const [activeId, setActiveId] = useState(images[0]?.id);
    const aspects = useMediaAspects();

    const active = images.find((image) => image.id === activeId) ?? images[0];
    const crop = crops[active.id] ?? DEFAULT_CROP;
    // A carousel's frame follows its first image, exactly as it publishes.
    const firstAspect = aspects.aspectOf(images[0]);
    const frameOn = (channel: string) =>
        frameFor(channel, format[channel], firstAspect, false);
    const frame = frameOn(platform);
    const stageRatio = frame.focus && frame.fit === 'crop' ? frame.ratio : null;
    const name = platformNames[platform] ?? platform;

    return (
        <>
            <DialogHeader>
                <DialogTitle>Frame your media.</DialogTitle>
                <DialogDescription>
                    Drag the box to choose what stays in shot. One focus point
                    per image carries across every channel.
                </DialogDescription>
            </DialogHeader>

            <div className="flex flex-wrap items-center justify-between gap-3">
                {platforms.length > 1 ? (
                    <div
                        role="tablist"
                        aria-label="Channel"
                        className="bg-muted flex max-w-full gap-1 overflow-x-auto rounded-full p-1"
                    >
                        {platforms.map((channel) => {
                            const Icon = platformIcon(channel);
                            const selected = channel === platform;

                            return (
                                <button
                                    key={channel}
                                    type="button"
                                    role="tab"
                                    aria-selected={selected}
                                    onClick={() => setPlatform(channel)}
                                    className={cn(
                                        'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs whitespace-nowrap transition-colors',
                                        selected
                                            ? 'bg-card text-foreground shadow-xs'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    <Icon className="size-3.5" />
                                    {platformNames[channel] ?? channel}
                                </button>
                            );
                        })}
                    </div>
                ) : (
                    <span className="eyebrow text-muted-foreground">
                        {name}
                    </span>
                )}
                <FormatPicker
                    platform={platform}
                    value={format[platform] ?? 'original'}
                    options={formatOptions}
                    onChange={(value) =>
                        setFormat((current) => ({
                            ...current,
                            [platform]: value,
                        }))
                    }
                />
            </div>

            <div className="grid min-w-0 gap-5 lg:grid-cols-[minmax(0,1fr)_200px]">
                <div className="grid min-w-0 content-start gap-3">
                    <FramingStage
                        asset={active}
                        ratio={stageRatio}
                        crop={crop}
                        aspect={aspects.aspectOf(active)}
                        onMeasure={aspects.measure}
                        onChange={(next) =>
                            setCrops((current) => ({
                                ...current,
                                [active.id]: next,
                            }))
                        }
                    />

                    {stageRatio === null && (
                        <p className="text-muted-foreground text-xs">
                            {platform === 'tiktok'
                                ? 'Original plays on black bars in TikTok photo mode — choose 9:16 to fill the screen.'
                                : `${name} shows the original and crops the feed thumbnail itself. Pick a format to frame it yourself.`}{' '}
                            The focus point still applies to channels that crop.
                        </p>
                    )}

                    <div className="flex items-center gap-3">
                        <label
                            htmlFor="crop-zoom"
                            className="text-sm font-medium"
                        >
                            Zoom
                        </label>
                        <input
                            id="crop-zoom"
                            type="range"
                            min={MIN_ZOOM}
                            max={MAX_ZOOM}
                            step={0.05}
                            value={crop.zoom}
                            onChange={(event) =>
                                setCrops((current) => ({
                                    ...current,
                                    [active.id]: normalizeCrop({
                                        ...crop,
                                        zoom: Number(event.target.value),
                                    }),
                                }))
                            }
                            className="accent-primary h-2 flex-1 cursor-pointer"
                        />
                        <span className="text-muted-foreground w-12 text-right font-mono text-xs tabular-nums">
                            {crop.zoom.toFixed(2)}×
                        </span>
                    </div>

                    {images.length > 1 && (
                        <div className="flex gap-2 overflow-x-auto pb-1">
                            {images.map((image, index) => (
                                <button
                                    key={image.id}
                                    type="button"
                                    onClick={() => setActiveId(image.id)}
                                    aria-label={`Frame image ${index + 1}`}
                                    aria-pressed={image.id === active.id}
                                    className={cn(
                                        'bg-muted relative size-14 shrink-0 overflow-hidden rounded-lg border-2 transition-colors',
                                        image.id === active.id
                                            ? 'border-primary'
                                            : 'hover:border-foreground/25 border-transparent',
                                    )}
                                >
                                    <img
                                        src={image.url ?? undefined}
                                        alt=""
                                        className="size-full object-cover"
                                    />
                                    <span className="bg-primary text-primary-foreground absolute top-0.5 left-0.5 flex size-4 items-center justify-center rounded-full font-mono text-[9px]">
                                        {index + 1}
                                    </span>
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                <div className="grid content-start gap-3">
                    <span className="eyebrow text-muted-foreground">
                        On every channel
                    </span>
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-1">
                        {platforms.map((channel) => {
                            const Icon = platformIcon(channel);
                            const channelFrame = frameOn(channel);

                            return (
                                <button
                                    key={channel}
                                    type="button"
                                    onClick={() => setPlatform(channel)}
                                    className={cn(
                                        'grid gap-1.5 rounded-xl border p-2 text-left transition-colors',
                                        channel === platform
                                            ? 'border-primary'
                                            : 'border-border hover:border-foreground/25',
                                    )}
                                >
                                    <span className="flex items-center justify-between gap-2 text-xs">
                                        <span className="flex items-center gap-1.5 font-medium">
                                            <Icon className="size-3.5" />
                                            {platformNames[channel] ?? channel}
                                        </span>
                                        <span className="text-muted-foreground font-mono text-[11px]">
                                            {channelFrame.fit === 'contain'
                                                ? 'Fit'
                                                : describeRatio(
                                                      channelFrame.ratio,
                                                  )}
                                        </span>
                                    </span>
                                    <div
                                        className={cn(
                                            'mx-auto w-full overflow-hidden rounded-md',
                                            channelFrame.ratio < 1 &&
                                                'max-w-[120px]',
                                        )}
                                    >
                                        <FramedMedia
                                            asset={active}
                                            frame={channelFrame}
                                            crop={crop}
                                            aspect={aspects.aspectOf(active)}
                                            onMeasure={aspects.measure}
                                        />
                                    </div>
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>

            <DialogFooter className="items-center gap-2 sm:justify-between">
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() =>
                        setCrops((current) => ({
                            ...current,
                            [active.id]: DEFAULT_CROP,
                        }))
                    }
                >
                    <RotateCcw />
                    Reset this image
                </Button>
                <span className="flex gap-2">
                    <Button type="button" variant="outline" onClick={onCancel}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={() => onApply(crops, format)}
                    >
                        Apply framing
                    </Button>
                </span>
            </DialogFooter>
        </>
    );
}

/**
 * Edit the post's image framing: per-channel format plus each image's focus point and zoom.
 * Works on a draft — nothing reaches the editor until "Apply framing", and nothing is saved until
 * the post is.
 */
export function CropEditorDialog({
    open,
    onOpenChange,
    assets,
    crops,
    platforms,
    activePlatform,
    mediaFormat,
    formatOptions,
    onApply,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    assets: PreviewAsset[];
    crops: Record<number, Crop>;
    /** The post's channels (unique platforms), in display order. */
    platforms: string[];
    activePlatform: string;
    mediaFormat: MediaFormat;
    formatOptions: MediaFormatOptions;
    onApply: (crops: Record<number, Crop>, format: MediaFormat) => void;
}) {
    const images = assets.filter((asset) => asset.url && isImageAsset(asset));

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-4xl">
                {open && images.length > 0 && (
                    <DialogBody
                        images={images}
                        initialCrops={crops}
                        platforms={platforms}
                        initialPlatform={activePlatform}
                        initialFormat={mediaFormat}
                        formatOptions={formatOptions}
                        onApply={(nextCrops, nextFormat) => {
                            onApply(nextCrops, nextFormat);
                            onOpenChange(false);
                        }}
                        onCancel={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
