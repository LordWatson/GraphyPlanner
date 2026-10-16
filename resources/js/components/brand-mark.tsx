import type { SVGAttributes } from 'react';
import type { Artwork } from '@/components/brand/artwork';
import {
    logoArtwork,
    markArtwork,
    snoutArtwork,
} from '@/components/brand/artwork';
import { cn } from '@/lib/utils';

/** Renders one piece of master artwork, single-colour, inheriting `currentColor`. */
export function ArtworkSvg({
    artwork,
    title,
    className,
    ...props
}: SVGAttributes<SVGSVGElement> & { artwork: Artwork; title?: string }) {
    return (
        <svg
            viewBox={artwork.viewBox}
            xmlns="http://www.w3.org/2000/svg"
            fill="currentColor"
            role={title ? 'img' : undefined}
            aria-hidden={title ? undefined : true}
            className={className}
            {...props}
        >
            {title && <title>{title}</title>}
            <path d={artwork.d} />
        </svg>
    );
}

// Heights keep the horizontal lock-up at or above its 120px minimum width (§2.5).
const logoHeights = {
    sm: 'h-[34px]',
    md: 'h-10',
    lg: 'h-14',
} as const;

const tileSizes = {
    sm: 'size-8 rounded-[0.5rem] [&>svg]:w-[72%]',
    md: 'size-10 rounded-[0.6rem] [&>svg]:w-[70%]',
    lg: 'size-14 rounded-[0.8rem] [&>svg]:w-[70%]',
} as const;

/**
 * The single source for Alima's logo (§2). With `wordmark`, renders the horizontal signature
 * lock-up in `text-logo` (Teal on Cream; Cream on Ink in dark mode) — override the colour with a
 * text class only to another approved colourway (§2.6). Without it, renders the app-icon tile:
 * the cream mark on a Deep Teal tile at 70% width, 22.5% corner radius (§2.9).
 *
 * Never hand-roll a logo, retype the wordmark, or tint the artwork.
 */
export function BrandMark({
    size = 'md',
    wordmark = false,
    caption,
    className,
}: {
    size?: keyof typeof logoHeights;
    wordmark?: boolean;
    caption?: string;
    className?: string;
}) {
    if (wordmark) {
        return (
            <span
                className={cn(
                    'text-logo inline-flex flex-col items-start gap-1.5',
                    className,
                )}
            >
                <ArtworkSvg
                    artwork={logoArtwork}
                    title="Alima"
                    className={cn('w-auto shrink-0', logoHeights[size])}
                />
                {caption && (
                    <span className="eyebrow text-[10px] leading-none opacity-70">
                        {caption}
                    </span>
                )}
            </span>
        );
    }

    return (
        <span
            className={cn(
                'bg-teal text-cream inline-flex shrink-0 items-center justify-center',
                tileSizes[size],
                className,
            )}
        >
            <ArtworkSvg
                artwork={markArtwork}
                title="Alima"
                className="h-auto"
            />
        </span>
    );
}

/**
 * The snout (§5.1) — the panda's nose and smile as our smallest signature. For list markers,
 * seals, loaders and sign-offs. Never next to the full mark, never rotated, min 12px.
 */
export function Snout({ className }: { className?: string }) {
    return (
        <ArtworkSvg
            artwork={snoutArtwork}
            className={cn('h-4 w-auto shrink-0', className)}
        />
    );
}
