import { Clapperboard, Copy, Film, ImageOff, Pin } from 'lucide-react';
import type { CSSProperties } from 'react';
import type { GridAsset, GridTileKind } from '@/components/grids/types';
import type { GridTileFrameResult } from '@/lib/grid-layout';
import { gridTileFrame, rectImageStyle } from '@/lib/grid-layout';
import type { CropRect } from '@/lib/media-format';
import { cn } from '@/lib/utils';

/** Brand palette token → placeholder surface (never a hex from input). */
export const placeholderClasses: Record<string, string> = {
    teal: 'bg-teal text-cream',
    electric: 'bg-electric text-ink',
    lilac: 'bg-lilac text-ink',
    lime: 'bg-lime text-ink',
    ink: 'bg-ink text-cream',
};

export type GridTileVisual = {
    kind: GridTileKind | 'outside' | 'feed';
    format?: string;
    placeholder_colour?: string | null;
    assets?: GridAsset[];
    cover?: GridAsset | null;
    frame?: GridTileFrameResult | null;
    /** A plain thumbnail (live feed, outside posts) — centre-cropped like Instagram does. */
    thumbnail_url?: string | null;
    media_type?: string;
};

function thumbnailOf(tile: GridTileVisual): GridAsset | null {
    return tile.cover ?? tile.assets?.[0] ?? null;
}

/**
 * The frame to draw: the server's when it sent one, else worked out here from the media's size
 * and framing with the same maths (`gridTileFrame()`), so an unsaved crop shows straight away.
 */
export function frameOf(
    tile: GridTileVisual,
    override?: { format?: string; crop?: GridAsset['crop'] },
): GridTileFrameResult | null {
    const asset = thumbnailOf(tile);

    if (!asset) {
        return null;
    }

    if (!override && tile.frame) {
        return tile.frame;
    }

    return gridTileFrame(
        asset.width,
        asset.height,
        override?.format ?? tile.format ?? '3:4',
        override?.crop ?? asset.crop,
    );
}

function relative(inner: CropRect, outer: CropRect): CropRect {
    return {
        x: (inner.x - outer.x) / outer.w,
        y: (inner.y - outer.y) / outer.h,
        w: inner.w / outer.w,
        h: inner.h / outer.h,
    };
}

/**
 * One tile as Instagram's 3:4 profile grid shows it. Images are cut exactly to the grid crop of
 * their published frame; with `cropTruth`, the whole published frame shows instead, with the part
 * the grid hides dimmed and the grid window outlined.
 */
export function GridTileImage({
    tile,
    cropTruth = false,
    override,
    className,
    sizes,
}: {
    tile: GridTileVisual;
    cropTruth?: boolean;
    override?: { format?: string; crop?: GridAsset['crop'] };
    className?: string;
    sizes?: string;
}) {
    const box = 'relative aspect-[3/4] w-full overflow-hidden';

    if (tile.kind === 'placeholder') {
        return (
            <div
                className={cn(
                    box,
                    'flex flex-col items-center justify-center gap-1 p-2 text-center',
                    placeholderClasses[tile.placeholder_colour ?? ''] ??
                        'bg-muted text-muted-foreground',
                    className,
                )}
            >
                <Pin className="size-4 opacity-70" />
                <span className="font-mono text-[10px] tracking-[0.08em] uppercase opacity-80">
                    Placeholder
                </span>
            </div>
        );
    }

    if (tile.thumbnail_url !== undefined && !tile.assets) {
        return (
            <div className={cn(box, 'bg-muted', className)}>
                {tile.thumbnail_url ? (
                    <img
                        src={tile.thumbnail_url}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        sizes={sizes}
                        className="absolute inset-0 size-full object-cover"
                    />
                ) : (
                    <span className="text-muted-foreground absolute inset-0 flex items-center justify-center">
                        <ImageOff className="size-4" />
                    </span>
                )}
                {tile.media_type === 'VIDEO' && (
                    <Clapperboard className="text-cream absolute top-1.5 right-1.5 size-3.5 drop-shadow" />
                )}
                {tile.media_type === 'CAROUSEL_ALBUM' && (
                    <Copy className="text-cream absolute top-1.5 right-1.5 size-3.5 drop-shadow" />
                )}
            </div>
        );
    }

    const asset = thumbnailOf(tile);
    const src = asset?.thumbnail_url ?? asset?.url ?? null;
    const isVideo = asset?.type === 'video';
    const frame = frameOf(tile, override);

    if (!asset || !src || (isVideo && !asset.thumbnail_url)) {
        return (
            <div
                className={cn(
                    box,
                    'bg-muted text-muted-foreground flex flex-col items-center justify-center gap-1',
                    className,
                )}
            >
                {isVideo ? (
                    <Film className="size-5" />
                ) : (
                    <ImageOff className="size-5" />
                )}
                <span className="text-[10px]">
                    {isVideo ? 'Video' : 'No media yet'}
                </span>
            </div>
        );
    }

    if (!frame) {
        return (
            <div className={cn(box, 'bg-muted', className)}>
                <img
                    src={src}
                    alt={asset.alt_text ?? ''}
                    loading="lazy"
                    decoding="async"
                    className="absolute inset-0 size-full object-cover"
                />
            </div>
        );
    }

    if (!cropTruth) {
        return (
            <div className={cn(box, 'bg-muted', className)}>
                <img
                    src={src}
                    alt={asset.alt_text ?? ''}
                    loading="lazy"
                    decoding="async"
                    style={rectImageStyle(frame.grid)}
                />
            </div>
        );
    }

    // Crop truth: the published frame, contained in the tile, the grid window outlined on it.
    const imageAspect =
        asset.width && asset.height ? asset.width / asset.height : 1;
    const frameAspect = (frame.frame.w * imageAspect) / frame.frame.h;
    const windowRect = relative(frame.grid, frame.frame);
    const frameStyle: CSSProperties =
        frameAspect >= 3 / 4
            ? { width: '100%', aspectRatio: frameAspect }
            : { height: '100%', aspectRatio: frameAspect };
    const dim = 'bg-ink/55 absolute';

    return (
        <div
            className={cn(
                box,
                'bg-foreground/5 flex items-center justify-center',
                className,
            )}
        >
            <div className="relative overflow-hidden" style={frameStyle}>
                <img
                    src={src}
                    alt={asset.alt_text ?? ''}
                    loading="lazy"
                    decoding="async"
                    style={rectImageStyle(frame.frame)}
                />
                {frame.cropped_on_grid && (
                    <>
                        <span
                            aria-hidden
                            className={dim}
                            style={{
                                left: 0,
                                top: 0,
                                width: '100%',
                                height: `${windowRect.y * 100}%`,
                            }}
                        />
                        <span
                            aria-hidden
                            className={dim}
                            style={{
                                left: 0,
                                top: `${(windowRect.y + windowRect.h) * 100}%`,
                                width: '100%',
                                bottom: 0,
                            }}
                        />
                        <span
                            aria-hidden
                            className={dim}
                            style={{
                                left: 0,
                                top: `${windowRect.y * 100}%`,
                                width: `${windowRect.x * 100}%`,
                                height: `${windowRect.h * 100}%`,
                            }}
                        />
                        <span
                            aria-hidden
                            className={dim}
                            style={{
                                left: `${(windowRect.x + windowRect.w) * 100}%`,
                                top: `${windowRect.y * 100}%`,
                                right: 0,
                                height: `${windowRect.h * 100}%`,
                            }}
                        />
                        <span
                            aria-hidden
                            className="border-lime absolute border border-dashed"
                            style={{
                                left: `${windowRect.x * 100}%`,
                                top: `${windowRect.y * 100}%`,
                                width: `${windowRect.w * 100}%`,
                                height: `${windowRect.h * 100}%`,
                            }}
                        />
                    </>
                )}
            </div>
        </div>
    );
}
