import type { LucideIcon } from 'lucide-react';
import {
    AtSign,
    ChartNoAxesColumn,
    Hash,
    Link2,
    MapPin,
    Trash2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import type { StoryExtraKey } from '@/lib/story-extras';
import { STORY_EXTRA_LABELS } from '@/lib/story-extras';
import { cn } from '@/lib/utils';

export const EXTRA_ICONS: Record<StoryExtraKey, LucideIcon> = {
    link: Link2,
    mentions: AtSign,
    hashtag: Hash,
    location: MapPin,
    poll: ChartNoAxesColumn,
};

/** One added extra: icon, name, its fields, and a way to take it off again. */
export function ExtraCard({
    extra,
    onRemove,
    children,
}: {
    extra: StoryExtraKey;
    onRemove: () => void;
    children: ReactNode;
}) {
    const Icon = EXTRA_ICONS[extra];
    const label = STORY_EXTRA_LABELS[extra];

    return (
        <li
            data-extra={extra}
            className="border-border bg-card grid gap-3 rounded-xl border p-3 sm:p-4"
        >
            <div className="flex items-center gap-2.5">
                <span className="bg-accent text-accent-foreground flex size-8 shrink-0 items-center justify-center rounded-full">
                    <Icon className="size-4" />
                </span>
                <span className="min-w-0 flex-1 text-sm font-semibold">
                    {label}
                </span>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="text-muted-foreground -me-1.5 size-11 sm:size-8"
                    onClick={onRemove}
                    aria-label={`Remove ${label.toLowerCase()}`}
                >
                    <Trash2 />
                </Button>
            </div>
            {children}
        </li>
    );
}

export function FieldHint({
    children,
    tone = 'muted',
}: {
    children: ReactNode;
    tone?: 'muted' | 'error';
}) {
    return (
        <p
            className={cn(
                'text-xs',
                tone === 'error' ? 'text-destructive' : 'text-muted-foreground',
            )}
        >
            {children}
        </p>
    );
}

export function Counter({ used, limit }: { used: number; limit: number }) {
    return (
        <span
            className={cn(
                'ms-auto font-mono text-[11px] tabular-nums',
                used > limit ? 'text-destructive' : 'text-muted-foreground',
            )}
        >
            {used}
            <span className="sr-only"> of {limit} characters</span>
            <span aria-hidden>/{limit}</span>
        </span>
    );
}
