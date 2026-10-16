import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';

const tileSizes = {
    sm: 'size-7 rounded-[0.55rem] [&>svg]:size-3.5',
    md: 'size-9 rounded-[0.7rem] [&>svg]:size-[18px]',
    lg: 'size-12 rounded-[0.9rem] [&>svg]:size-6',
} as const;

const wordmarkSizes = {
    sm: 'text-xl',
    md: 'text-2xl',
    lg: 'text-4xl',
} as const;

/**
 * The single source for AlimaPlanner's logo lock-up: an orange tile carrying the mark, optionally
 * followed by the lower-case italic serif wordmark "alimaplanner." Use this instead of hand-rolling a
 * gradient square + `AppLogoIcon` on each page.
 */
export function BrandMark({
    size = 'md',
    wordmark = false,
    caption,
    className,
}: {
    size?: keyof typeof tileSizes;
    wordmark?: boolean;
    caption?: string;
    className?: string;
}) {
    return (
        <span className={cn('inline-flex items-center gap-2.5', className)}>
            <span
                className={cn(
                    'bg-primary text-primary-foreground flex shrink-0 items-center justify-center shadow-[inset_0_1px_0_oklch(1_0_0/0.25)]',
                    tileSizes[size],
                )}
            >
                <AppLogoIcon className="fill-current" />
            </span>
            {wordmark && (
                <span className="flex flex-col leading-none">
                    <span
                        className={cn(
                            'font-serif leading-none italic',
                            wordmarkSizes[size],
                        )}
                    >
                        alimaplanner<span className="text-primary not-italic">.</span>
                    </span>
                    {caption && (
                        <span className="eyebrow mt-1 text-[9.5px] opacity-60">
                            {caption}
                        </span>
                    )}
                </span>
            )}
        </span>
    );
}
