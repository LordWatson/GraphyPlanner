import { ChevronLeft, ChevronRight, ImageOff } from 'lucide-react';
import type { CSSProperties } from 'react';
import { useCallback, useState } from 'react';
import type { PreviewAsset } from '@/components/asset-preview-carousel';
import type { Crop, MediaFrame } from '@/lib/media-format';
import { cropBox, DEFAULT_CROP, normalizeCrop } from '@/lib/media-format';
import { cn } from '@/lib/utils';

export function isVideoAsset(asset: PreviewAsset): boolean {
    if (asset.type) {
        return asset.type.startsWith('video');
    }

    return /\.(mp4|mov|webm|m4v)$/i.test(asset.url ?? '');
}

export function isImageAsset(asset: PreviewAsset): boolean {
    if (asset.type) {
        return asset.type.startsWith('image');
    }

    return /\.(png|jpe?g|gif|webp|avif)$/i.test(asset.url ?? '');
}

/**
 * The media a channel actually receives, in order: the first video alone when there is one
 * (the video endpoint takes a single file), otherwise every image. Documents never publish.
 */
export function publishableMedia(assets: PreviewAsset[]): PreviewAsset[] {
    const video = assets.find(isVideoAsset);

    return video ? [video] : assets.filter(isImageAsset);
}

/**
 * Width ÷ height per asset id: the stored pixel size when the server sent one, otherwise
 * measured from the element once it loads.
 */
export function useMediaAspects() {
    const [measured, setMeasured] = useState<Record<number, number>>({});

    const aspectOf = useCallback(
        (asset: PreviewAsset | undefined): number | null => {
            if (!asset) {
                return null;
            }

            if (measured[asset.id]) {
                return measured[asset.id];
            }

            return asset.width && asset.height
                ? asset.width / asset.height
                : null;
        },
        [measured],
    );

    const measure = useCallback((id: number, aspect: number) => {
        if (!Number.isFinite(aspect) || aspect <= 0) {
            return;
        }

        setMeasured((current) =>
            Math.abs((current[id] ?? 0) - aspect) < 0.001
                ? current
                : { ...current, [id]: aspect },
        );
    }, []);

    return { aspectOf, measure };
}

export function cropFor(
    asset: PreviewAsset,
    crops: Record<number, Crop> | undefined,
): Crop {
    return normalizeCrop(crops?.[asset.id] ?? asset.crop ?? DEFAULT_CROP);
}

/**
 * One media item inside a channel's frame. With a known shape and `frame.focus`, the image is
 * positioned so exactly the publisher's crop box shows; otherwise it centre-crops (or
 * letterboxes, for `contain`) the way the platform would.
 */
export function FramedMedia({
    asset,
    frame,
    crop,
    aspect,
    onMeasure,
    fill = false,
    className,
}: {
    asset: PreviewAsset | undefined;
    frame: MediaFrame;
    crop: Crop;
    aspect: number | null;
    onMeasure: (id: number, aspect: number) => void;
    /** Fill the parent instead of sizing to the frame's ratio (grids, full-screen layouts). */
    fill?: boolean;
    className?: string;
}) {
    const sizing: CSSProperties | undefined = fill
        ? undefined
        : { aspectRatio: frame.ratio };

    if (!asset?.url) {
        return (
            <div
                style={sizing}
                className={cn(
                    'bg-muted text-muted-foreground flex w-full flex-col items-center justify-center gap-1.5 text-xs',
                    fill && 'absolute inset-0',
                    className,
                )}
            >
                <ImageOff className="size-5" />
                No media yet
            </div>
        );
    }

    const contain = frame.fit === 'contain';
    let mediaStyle: CSSProperties | undefined;
    let mediaClass = cn(
        'absolute inset-0 size-full',
        contain ? 'object-contain' : 'object-cover',
    );

    if (!contain && frame.focus && aspect) {
        const box = cropBox(aspect, frame.ratio, crop);

        if (fill) {
            // Grid cells have their own shape: centre the platform's crop on the focus point.
            mediaStyle = {
                objectPosition: `${(crop.x * 100).toFixed(2)}% ${(crop.y * 100).toFixed(2)}%`,
            };
        } else {
            mediaClass = 'absolute max-w-none';
            mediaStyle = {
                width: `${100 / box.w}%`,
                height: `${100 / box.h}%`,
                left: `${(-box.x / box.w) * 100}%`,
                top: `${(-box.y / box.h) * 100}%`,
            };
        }
    }

    return (
        <div
            style={sizing}
            className={cn(
                'relative w-full overflow-hidden',
                contain ? 'bg-ink' : 'bg-muted',
                fill && 'absolute inset-0',
                className,
            )}
        >
            {isVideoAsset(asset) ? (
                <video
                    src={asset.url}
                    className={mediaClass}
                    style={mediaStyle}
                    muted
                    playsInline
                    loop
                    autoPlay
                    preload="metadata"
                    onLoadedMetadata={(event) => {
                        const { videoWidth, videoHeight } = event.currentTarget;

                        if (videoWidth && videoHeight) {
                            onMeasure(asset.id, videoWidth / videoHeight);
                        }
                    }}
                />
            ) : (
                <img
                    src={asset.url}
                    alt={asset.original_filename ?? ''}
                    draggable={false}
                    className={mediaClass}
                    style={mediaStyle}
                    onLoad={(event) => {
                        const { naturalWidth, naturalHeight } =
                            event.currentTarget;

                        if (naturalWidth && naturalHeight) {
                            onMeasure(asset.id, naturalWidth / naturalHeight);
                        }
                    }}
                />
            )}
        </div>
    );
}

/** Prev/next arrows for a swipeable carousel (hidden at either end, like the apps). */
export function CarouselArrows({
    index,
    count,
    onChange,
}: {
    index: number;
    count: number;
    onChange: (index: number) => void;
}) {
    if (count < 2) {
        return null;
    }

    const arrow =
        'bg-card/90 text-foreground absolute top-1/2 z-10 flex size-7 -translate-y-1/2 items-center justify-center rounded-full shadow-sm transition-opacity hover:bg-card focus-visible:ring-ring/50 outline-none focus-visible:ring-[3px]';

    return (
        <>
            {index > 0 && (
                <button
                    type="button"
                    aria-label="Previous slide"
                    onClick={() => onChange(index - 1)}
                    className={cn(arrow, 'left-2')}
                >
                    <ChevronLeft className="size-4" />
                </button>
            )}
            {index < count - 1 && (
                <button
                    type="button"
                    aria-label="Next slide"
                    onClick={() => onChange(index + 1)}
                    className={cn(arrow, 'right-2')}
                >
                    <ChevronRight className="size-4" />
                </button>
            )}
        </>
    );
}

/** Carousel position dots. `tone="light"` sits on media, `tone="dark"` on the card. */
export function CarouselDots({
    index,
    count,
    onChange,
    tone = 'dark',
    className,
}: {
    index: number;
    count: number;
    onChange: (index: number) => void;
    tone?: 'light' | 'dark';
    className?: string;
}) {
    if (count < 2) {
        return null;
    }

    return (
        <div className={cn('flex items-center gap-1', className)}>
            {Array.from({ length: count }, (_, i) => (
                <button
                    key={i}
                    type="button"
                    aria-label={`Show slide ${i + 1}`}
                    aria-current={i === index}
                    onClick={() => onChange(i)}
                    className={cn(
                        'size-1.5 rounded-full transition-colors',
                        tone === 'light'
                            ? i === index
                                ? 'bg-cream'
                                : 'bg-cream/45'
                            : i === index
                              ? 'bg-info'
                              : 'bg-muted-foreground/35',
                    )}
                />
            ))}
        </div>
    );
}

/**
 * Where a full-screen app's own chrome sits over the media (status/tabs on top, caption and
 * actions below, the engagement rail on the right). Keep text and faces out of these.
 */
export function SafeZones({ platform }: { platform: string }) {
    const bottom = platform === 'tiktok' ? '26%' : '22%';
    const zone =
        'border-electric bg-electric/20 absolute flex items-start justify-center border border-dashed p-1';
    const label =
        'bg-ink/75 text-cream rounded-full px-1.5 py-px font-mono text-[9px] tracking-[0.08em] uppercase';

    return (
        <div className="pointer-events-none absolute inset-0 z-20" aria-hidden>
            <div
                className={zone}
                style={{ top: 0, left: 0, right: 0, height: '11%' }}
            >
                <span className={label}>App UI</span>
            </div>
            <div
                className={cn(zone, 'items-end')}
                style={{ bottom: 0, left: 0, right: 0, height: bottom }}
            >
                <span className={label}>Caption & actions</span>
            </div>
            <div
                className={cn(zone, 'items-center')}
                style={{ top: '30%', right: 0, width: '17%', bottom }}
            >
                <span className={cn(label, '[writing-mode:vertical-rl]')}>
                    Actions
                </span>
            </div>
        </div>
    );
}

/**
 * Facebook/LinkedIn multi-image layout: 2 side by side, 3 as one large over two, 4+ as one large
 * over three with a "+N" on the last tile.
 */
export function MediaGrid({
    media,
    frame,
    crops,
    aspectOf,
    onMeasure,
}: {
    media: PreviewAsset[];
    frame: MediaFrame;
    crops: Record<number, Crop> | undefined;
    aspectOf: (asset: PreviewAsset | undefined) => number | null;
    onMeasure: (id: number, aspect: number) => void;
}) {
    const shown = media.slice(0, 4);
    const extra = media.length - shown.length;

    const layout =
        shown.length === 2
            ? 'grid-cols-2 grid-rows-1'
            : shown.length === 3
              ? 'grid-cols-2 grid-rows-[2fr_1fr]'
              : 'grid-cols-3 grid-rows-[2fr_1fr]';

    return (
        <div className={cn('grid aspect-square w-full gap-0.5', layout)}>
            {shown.map((asset, i) => (
                <div
                    key={asset.id}
                    className={cn(
                        'relative overflow-hidden',
                        i === 0 && shown.length === 3 && 'col-span-2',
                        i === 0 && shown.length >= 4 && 'col-span-3',
                    )}
                >
                    <FramedMedia
                        asset={asset}
                        frame={frame}
                        crop={cropFor(asset, crops)}
                        aspect={aspectOf(asset)}
                        onMeasure={onMeasure}
                        fill
                    />
                    {extra > 0 && i === shown.length - 1 && (
                        <span className="bg-ink/55 text-cream absolute inset-0 flex items-center justify-center text-2xl font-semibold">
                            +{extra}
                        </span>
                    )}
                </div>
            ))}
        </div>
    );
}
