import { Link } from '@inertiajs/react';
import { ClipboardCheck, LayoutGrid, ListChecks } from 'lucide-react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import { dashboard, needsAttention } from '@/routes';
import { index as myWorkIndex } from '@/routes/my-work';

/**
 * MOVE 1 (ux-overhaul): the one front door. Overview, Needs attention and My work are no longer
 * three competing sidebar chapters — they are three views of a single `01 Home` chapter, switched
 * here. Rendered under the header on each of the three pages so Home reads as one place.
 *
 * Segmented pills on a muted track (brand §6 "Tabs"): active = a card lifted on the track.
 */
const views = [
    {
        label: 'Studio',
        href: dashboard(),
        icon: LayoutGrid,
        hint: 'The studio today',
    },
    {
        label: 'Needs attention',
        href: needsAttention(),
        icon: ListChecks,
        hint: 'The decision queue',
    },
    {
        label: 'Mine',
        href: myWorkIndex(),
        icon: ClipboardCheck,
        hint: 'Your own work',
    },
] as const;

export function HomeViewTabs({
    attentionCount,
    className,
}: {
    /** Optional badge on the "Needs attention" pill — decisions waiting on the studio. */
    attentionCount?: number;
    className?: string;
}) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <nav
            aria-label="Home views"
            className={cn(
                'border-border/70 bg-muted/40 inline-flex max-w-full gap-1 overflow-x-auto rounded-full border p-1',
                className,
            )}
        >
            {views.map((view) => {
                const active = isCurrentOrParentUrl(view.href);

                return (
                    <Link
                        key={view.label}
                        href={view.href}
                        prefetch
                        aria-current={active ? 'page' : undefined}
                        title={view.hint}
                        className={cn(
                            'flex shrink-0 items-center gap-2 rounded-full px-4 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                            active
                                ? 'bg-card text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <view.icon className="size-4 shrink-0" />
                        <span>{view.label}</span>
                        {view.label === 'Needs attention' &&
                        attentionCount &&
                        attentionCount > 0 ? (
                            <span
                                aria-hidden
                                className="bg-foreground/10 text-foreground ml-0.5 inline-flex min-w-5 items-center justify-center rounded-full px-1.5 font-mono text-[11px] tabular-nums"
                            >
                                {attentionCount}
                            </span>
                        ) : null}
                    </Link>
                );
            })}
        </nav>
    );
}
