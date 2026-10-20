import { Plus } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import type {
    ChannelState,
    TargetAccountData,
} from '@/components/post-editor/types';
import { Button } from '@/components/ui/button';
import { publishedCaption } from '@/lib/media-format';
import type { PlatformCapability, PlatformRegistry } from '@/lib/platforms';
import { capabilityOf } from '@/lib/platforms';
import { cn } from '@/lib/utils';

/** What the composer is editing: the main post every channel inherits, or one channel's own take. */
export type ComposerScope = 'all' | number;

export type MasterContent = {
    caption: string;
    hashtags: string[];
    link: string;
    firstComment: string;
};

export const AUTO = '__auto';

export const captionClass =
    'border-input bg-card focus-visible:border-ring focus-visible:ring-ring/40 dark:bg-input/30 placeholder:text-muted-foreground flex min-h-44 w-full resize-y rounded-lg border px-4 py-3.5 text-[15px] leading-relaxed outline-none focus-visible:ring-[3px]';

/** The caption as it publishes on a channel, against that channel's limit. */
export function usageFor(
    account: TargetAccountData,
    channel: ChannelState,
    master: MasterContent,
    registry: PlatformRegistry,
): { used: number; limit: number; capability: PlatformCapability } {
    const capability = capabilityOf(registry, account.platform);

    return {
        used: publishedCaption(
            channel.caption ?? master.caption,
            channel.hashtags ?? master.hashtags,
            capability,
            channel.link ?? master.link,
        ).length,
        limit: capability.caption_limit,
        capability,
    };
}

export function sameTags(a: string[], b: string[]): boolean {
    return a.length === b.length && a.every((tag, i) => tag === b[i]);
}

/** Where this channel puts the post's link, in plain words. */
export function linkHint(capability: PlatformCapability): string {
    if (capability.link_field) {
        return `Sent as the post’s own link on ${capability.label}, not in the caption.`;
    }

    return capability.links_in_caption
        ? 'Added to the end of the caption.'
        : `${capability.label} doesn’t show links on posts — use the link in bio.`;
}

/** A thin bar + mono count: quiet while there's room, warning near the limit, red over it. */
export function CaptionMeter({
    used,
    limit,
    label,
}: {
    used: number;
    limit: number | null;
    label?: string;
}) {
    if (limit === null) {
        return (
            <span className="text-muted-foreground font-mono text-[11px] tabular-nums">
                {used.toLocaleString()} chars
            </span>
        );
    }

    const ratio = limit > 0 ? used / limit : 0;
    const tone =
        ratio > 1 ? 'destructive' : ratio >= 0.9 ? 'warning' : 'neutral';

    return (
        <span className="flex items-center gap-2.5">
            <span
                className="bg-muted relative h-1 w-20 overflow-hidden rounded-full"
                aria-hidden
            >
                <span
                    className={cn(
                        'absolute inset-y-0 left-0 rounded-full transition-[width,background-color]',
                        tone === 'destructive' && 'bg-destructive',
                        tone === 'warning' && 'bg-warning',
                        tone === 'neutral' && 'bg-primary',
                    )}
                    style={{ width: `${Math.min(ratio, 1) * 100}%` }}
                />
            </span>
            <span
                className={cn(
                    'font-mono text-[11px] tabular-nums',
                    tone === 'destructive'
                        ? 'text-destructive'
                        : tone === 'warning'
                          ? 'text-warning'
                          : 'text-muted-foreground',
                )}
            >
                {used.toLocaleString()}/{limit.toLocaleString()}
                {label && <span className="ms-1">{label}</span>}
                {tone === 'destructive' && ' — too long'}
            </span>
        </span>
    );
}

/** A "+ Link"-style chip that reveals an optional field. */
export function AddChip({
    icon: Icon,
    children,
    onClick,
}: {
    icon: LucideIcon;
    children: ReactNode;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="border-border text-muted-foreground hover:text-foreground hover:border-foreground/30 focus-visible:ring-ring/50 inline-flex h-8 items-center gap-1.5 rounded-full border border-dashed px-3 text-xs font-medium transition-colors outline-none focus-visible:ring-[3px]"
        >
            <Plus className="size-3" />
            <Icon className="size-3.5" />
            {children}
        </button>
    );
}

/** Small mono tag saying whether a field follows the main post or is this channel's own. */
export function InheritTag({
    own,
    channelLabel,
    onReset,
}: {
    own: boolean;
    channelLabel: string;
    onReset: () => void;
}) {
    return own ? (
        <span className="flex items-center gap-1.5">
            <span className="text-primary font-mono text-[10px] tracking-[0.08em] uppercase">
                Only on {channelLabel}
            </span>
            <Button
                type="button"
                variant="link"
                className="h-auto p-0 text-[11px]"
                onClick={onReset}
            >
                Reset
            </Button>
        </span>
    ) : (
        <span className="text-muted-foreground font-mono text-[10px] tracking-[0.08em] uppercase">
            Same as all channels
        </span>
    );
}
