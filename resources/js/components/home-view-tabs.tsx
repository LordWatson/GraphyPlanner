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
 * Segmented pills on a muted track (brand §6 "Tabs"): active = a card lifted on the track. Each view
 * carries its icon in a tint circle and names its own job inline (so the labels aren't cryptic) —
 * a deliberately large, obvious switcher rather than a passive filter strip.
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
                'border-border/70 bg-muted/40 inline-flex max-w-full gap-1.5 overflow-x-auto rounded-full border p-1.5',
                className,
            )}
        >
            {views.map((view) => {
                const active = isCurrentOrParentUrl(view.href);
                const showCount =
                    view.label === 'Needs attention' &&
                    typeof attentionCount === 'number' &&
                    attentionCount > 0;

                return (
                    <Link
                        key={view.label}
                        href={view.href}
                        prefetch
                        aria-current={active ? 'page' : undefined}
                        title={view.hint}
                        className={cn(
                            'group flex shrink-0 items-center gap-3 rounded-full py-2 pr-4 pl-2.5 text-left whitespace-nowrap transition-colors',
                            active
                                ? 'bg-card text-foreground shadow-sm'
                                : 'text-muted-foreground hover:bg-card/60 hover:text-foreground',
                        )}
                    >
                        <span
                            aria-hidden
                            className={cn(
                                'grid size-9 shrink-0 place-items-center rounded-full transition-colors',
                                active
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-foreground/5 text-muted-foreground group-hover:bg-foreground/10',
                            )}
                        >
                            <view.icon className="size-[18px]" />
                        </span>

                        <span className="flex min-w-0 flex-col">
                            <span className="flex items-center gap-2">
                                <span
                                    className={cn(
                                        'text-[15px] leading-tight font-semibold',
                                        active
                                            ? 'text-foreground'
                                            : 'text-foreground/80 group-hover:text-foreground',
                                    )}
                                >
                                    {view.label}
                                </span>
                                {showCount ? (
                                    <span className="bg-primary text-primary-foreground inline-flex min-w-5 items-center justify-center rounded-full px-1.5 py-px font-mono text-[11px] leading-none font-medium tabular-nums">
                                        {attentionCount}
                                    </span>
                                ) : null}
                            </span>
                            <span className="text-muted-foreground hidden text-xs leading-tight sm:block">
                                {view.hint}
                            </span>
                        </span>
                    </Link>
                );
            })}
        </nav>
    );
}
