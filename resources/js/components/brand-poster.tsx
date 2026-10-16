import { ArtworkSvg } from '@/components/brand-mark';
import {
    confettiArtwork,
    markArtwork,
    squiggleArtwork,
} from '@/components/brand/artwork';
import { cn } from '@/lib/utils';

/**
 * Approved two-colour pattern pairs (§5.2–5.4): stroke colour / ground. Patterns crop freely and
 * have no edges; never recolour into three colours, never run body text over them (put text on a
 * solid plate above), and never mix squiggle and confetti in one layout.
 */
const patternPairs = {
    squiggle: {
        'lilac-orange': 'bg-signal text-lilac',
        'ink-teal': 'bg-teal text-ink',
        'cream-lime': 'bg-lime text-cream',
    },
    confetti: {
        'cream-ink': 'bg-ink text-cream',
        'teal-lilac': 'bg-lilac text-teal',
    },
} as const;

type PatternProps =
    | {
          variant: 'squiggle';
          pair?: keyof (typeof patternPairs)['squiggle'];
          className?: string;
      }
    | {
          variant: 'confetti';
          pair?: keyof (typeof patternPairs)['confetti'];
          className?: string;
      };

/** A full-bleed brand pattern. Fills (and crops to) its box. Decorative only. */
export function BrandPattern(props: PatternProps) {
    const pairClass =
        props.variant === 'squiggle'
            ? patternPairs.squiggle[props.pair ?? 'lilac-orange']
            : patternPairs.confetti[props.pair ?? 'cream-ink'];

    return (
        <div
            aria-hidden
            className={cn(
                'relative overflow-hidden',
                pairClass,
                props.className,
            )}
        >
            <ArtworkSvg
                artwork={
                    props.variant === 'squiggle'
                        ? squiggleArtwork
                        : confettiArtwork
                }
                preserveAspectRatio="xMidYMid slice"
                // Absolute fill: the box sets the size; the artwork's tall intrinsic ratio
                // must never stretch the row it sits in.
                className="absolute inset-0 size-full"
            />
        </div>
    );
}

/**
 * The peek (§5.5): the panda scaled up and cropped by its frame — curious, peeking in. Place it
 * inside a `relative overflow-hidden` container; it bleeds off the bottom edge with both eyes in
 * frame. One peek per layout, one colour (set with a text class), at least 3× any logo nearby.
 */
export function Peek({ className }: { className?: string }) {
    return (
        <ArtworkSvg
            artwork={markArtwork}
            className={cn(
                'pointer-events-none absolute bottom-0 h-auto translate-y-[34%]',
                className,
            )}
        />
    );
}

/**
 * The auth / marketing poster: a squiggle panel on a solid plate. Purely decorative; used on
 * auth and marketing surfaces only.
 */
export function BrandPoster({ className }: { className?: string }) {
    return (
        <BrandPattern
            variant="squiggle"
            pair="lilac-orange"
            className={cn('aspect-[4/5] rounded-2xl', className)}
        />
    );
}
