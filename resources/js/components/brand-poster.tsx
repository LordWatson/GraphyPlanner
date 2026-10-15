import { cn } from '@/lib/utils';

/**
 * The brand mark's four shapes — square, quarter-circle, square, circle — blown up into a poster
 * composition. Purely decorative; used on auth and marketing surfaces only (guidelines §1.4).
 */
export function BrandPoster({ className }: { className?: string }) {
    return (
        <div
            aria-hidden
            className={cn('grid aspect-square grid-cols-2 gap-3', className)}
        >
            <div className="border-ink-foreground/25 rounded-[18%] border" />
            <div className="bg-primary rounded-tr-full" />
            <div className="bg-ink-foreground/90 rounded-[18%]" />
            <div className="border-ink-foreground/25 relative rounded-full border">
                <div className="bg-primary/90 absolute inset-[22%] rounded-full" />
            </div>
        </div>
    );
}
