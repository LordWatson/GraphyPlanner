import { Check, Copy } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import type {
    HandoffExtra,
    HandoffMedia,
} from '@/components/story-handoff/types';
import type { HandoffMediaState } from '@/components/story-handoff/use-handoff-media';
import { Button } from '@/components/ui/button';
import { copyText } from '@/lib/story-share';
import { cn } from '@/lib/utils';

/** The Story, 9:16, in a phone-shaped frame — what the poster is about to share. */
export function HandoffMediaFrame({
    media,
    state,
    handle,
}: {
    media: HandoffMedia | null;
    state: HandoffMediaState;
    handle: string;
}) {
    return (
        <figure className="mx-auto w-full max-w-[236px]">
            <div className="bg-ink shadow-paper dark:ring-cream/10 relative aspect-[9/16] w-full overflow-hidden rounded-[2rem] p-1.5 dark:ring-1">
                <div className="bg-ink relative size-full overflow-hidden rounded-[1.6rem]">
                    {!media ? (
                        <p className="text-ink-foreground/70 flex size-full items-center justify-center p-6 text-center text-sm">
                            This story has no media attached.
                        </p>
                    ) : state.previewUrl === null ? (
                        <div
                            role="status"
                            aria-label="Loading the media"
                            className="bg-ink-foreground/15 size-full motion-safe:animate-pulse"
                        />
                    ) : media.kind === 'video' ? (
                        <video
                            src={state.previewUrl}
                            className="size-full object-cover"
                            controls
                            playsInline
                            muted
                            preload="metadata"
                            aria-label={`The story video for ${handle}`}
                        />
                    ) : (
                        <img
                            src={state.previewUrl}
                            alt={`The story image for ${handle}`}
                            className="size-full object-cover"
                            draggable={false}
                        />
                    )}
                </div>
            </div>
        </figure>
    );
}

/**
 * "Then add these in Instagram": one row per thing to add, each with its own Copy, so the poster
 * can come back for the poll text after pasting the link.
 */
export function HandoffExtrasList({
    extras,
    highlight = false,
}: {
    extras: HandoffExtra[];
    /** Draw the eye here once the media has been shared. */
    highlight?: boolean;
}) {
    const [copied, setCopied] = useState<number | null>(null);
    const resetRef = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(resetRef.current), []);

    if (extras.length === 0) {
        return null;
    }

    const copy = async (extra: HandoffExtra, index: number) => {
        if (await copyText(extra.value)) {
            setCopied(index);
            window.clearTimeout(resetRef.current);
            resetRef.current = window.setTimeout(() => setCopied(null), 2000);
            toast.success(`${extra.label} copied.`);
        } else {
            toast.error('Couldn’t copy that. Press and hold the text instead.');
        }
    };

    return (
        <section
            aria-labelledby="handoff-extras-title"
            className={cn(
                'border-border bg-card rounded-2xl border p-5 shadow-sm transition-shadow',
                highlight && 'ring-ring/40 ring-[3px]',
            )}
        >
            <h2
                id="handoff-extras-title"
                className="text-[17px] leading-snug font-semibold"
            >
                Then add these in Instagram
            </h2>
            <ol className="divide-border/70 mt-2 divide-y">
                {extras.map((extra, index) => (
                    <li
                        key={`${extra.key}-${index}`}
                        className="flex flex-wrap items-center gap-x-3 gap-y-2 py-3"
                    >
                        <span
                            aria-hidden
                            className="bg-accent text-accent-foreground flex size-7 shrink-0 items-center justify-center rounded-full font-mono text-xs tabular-nums"
                        >
                            {index + 1}
                        </span>
                        <span className="grid min-w-0 flex-1 basis-40 grid-cols-[minmax(0,1fr)] gap-0.5">
                            <span className="eyebrow text-muted-foreground">
                                {extra.label}
                            </span>
                            <span
                                dir="auto"
                                className="text-[15px] leading-snug [overflow-wrap:anywhere] select-all"
                            >
                                {extra.value}
                            </span>
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11 w-full shrink-0 px-4 sm:w-auto"
                            onClick={() => void copy(extra, index)}
                            aria-label={`Copy ${extra.label.toLowerCase()}`}
                        >
                            {copied === index ? <Check /> : <Copy />}
                            {copied === index ? 'Copied' : 'Copy'}
                        </Button>
                    </li>
                ))}
            </ol>
            <p aria-live="polite" className="sr-only">
                {copied === null ? '' : `${extras[copied]?.label} copied`}
            </p>
        </section>
    );
}

/** A quiet, full-width notice: status is the words, the tone is only a tint. */
export function HandoffNotice({
    tone = 'info',
    title,
    children,
}: {
    tone?: 'info' | 'warning';
    title: string;
    children?: React.ReactNode;
}) {
    return (
        <div
            className={cn(
                'rounded-xl border px-4 py-3 text-sm',
                tone === 'warning'
                    ? 'border-warning/30 bg-warning/10'
                    : 'border-info/25 bg-info/10',
            )}
        >
            <p
                className={cn(
                    'font-semibold',
                    tone === 'warning' ? 'text-warning' : 'text-info',
                )}
            >
                {title}
            </p>
            {children && (
                <p className="text-foreground/80 mt-0.5 text-pretty">
                    {children}
                </p>
            )}
        </div>
    );
}
