import { Link } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useNavGroups } from '@/hooks/use-nav-groups';
import { cn, toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

/**
 * The studio's phone navigation — a fixed, thumb-reachable bottom tab bar plus a "More" sheet for
 * everything that doesn't fit. On desktop the teal sidebar does this job (this is `md:hidden`); on a
 * phone the sidebar would only ever be a slide-over, which buries navigation behind a tap. Both read
 * from the same `useNavGroups` model, so the two shells always agree on who sees which chapter.
 *
 * Brand: the active tab is the Deep Teal brand action (Acid Lime in dark) — not the decorative
 * accents — set in a soft tint chip, exactly the selection language of the sidebar's active pill.
 */
export function MobileNav() {
    const { groups } = useNavGroups();
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const [moreOpen, setMoreOpen] = useState(false);

    const primary = groups[0]?.items ?? [];
    // The four chapters a studio reaches for most sit on the bar; the rest live under "More".
    const barItems = primary.slice(0, 4);
    const barHrefs = new Set(barItems.map((item) => toUrl(item.href)));

    const isItemActive = (item: NavItem): boolean =>
        item.isActive ??
        (isCurrentOrParentUrl(item.href) ||
            (item.subItems?.some((sub) => isCurrentOrParentUrl(sub.href)) ??
                false));

    const moreActive = groups
        .flatMap((group) => group.items)
        .some((item) => !barHrefs.has(toUrl(item.href)) && isItemActive(item));

    return (
        <>
            <nav
                aria-label="Primary"
                className="border-border/70 bg-background/95 supports-[backdrop-filter]:bg-background/80 fixed inset-x-0 bottom-0 z-40 border-t pb-[env(safe-area-inset-bottom)] backdrop-blur-md md:hidden"
            >
                <div className="mx-auto grid max-w-md grid-cols-5">
                    {barItems.map((item) => (
                        <TabLink
                            key={item.title}
                            href={item.href}
                            title={item.title}
                            icon={item.icon ?? undefined}
                            active={isItemActive(item)}
                        />
                    ))}
                    <button
                        type="button"
                        onClick={() => setMoreOpen(true)}
                        aria-current={moreActive ? 'page' : undefined}
                        aria-haspopup="dialog"
                        className="flex min-h-[56px] flex-col items-center justify-center gap-1 px-1 pt-1.5 pb-1 text-[10px] font-medium"
                    >
                        <span
                            className={cn(
                                'flex h-7 w-10 items-center justify-center rounded-full transition-colors',
                                moreActive
                                    ? 'bg-primary/12 text-primary'
                                    : 'text-muted-foreground',
                            )}
                        >
                            <MoreHorizontal className="size-[22px]" />
                        </span>
                        <span
                            className={cn(
                                moreActive
                                    ? 'text-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            More
                        </span>
                    </button>
                </div>
            </nav>

            <MoreSheet
                open={moreOpen}
                onOpenChange={setMoreOpen}
                groups={groups}
                isItemActive={isItemActive}
            />
        </>
    );
}

/** A bottom-bar tab: icon in a chip over a label, the active one in the brand tint. */
function TabLink({
    href,
    title,
    icon: Icon,
    active,
}: {
    href: NavItem['href'];
    title: string;
    icon?: LucideIcon;
    active: boolean;
}) {
    return (
        <Link
            href={href}
            prefetch
            aria-current={active ? 'page' : undefined}
            className="flex min-h-[56px] flex-col items-center justify-center gap-1 px-1 pt-1.5 pb-1 text-[10px] font-medium"
        >
            <span
                className={cn(
                    'flex h-7 w-10 items-center justify-center rounded-full transition-colors',
                    active
                        ? 'bg-primary/12 text-primary'
                        : 'text-muted-foreground',
                )}
            >
                {Icon && <Icon className="size-[22px]" />}
            </span>
            <span
                className={cn(
                    active ? 'text-foreground' : 'text-muted-foreground',
                )}
            >
                {title}
            </span>
        </Link>
    );
}

/** The "More" sheet — every destination, laid out like the sidebar's Contents groups. */
function MoreSheet({
    open,
    onOpenChange,
    groups,
    isItemActive,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    groups: ReturnType<typeof useNavGroups>['groups'];
    isItemActive: (item: NavItem) => boolean;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="rounded-t-2xl pb-[max(1.5rem,env(safe-area-inset-bottom))]"
            >
                <SheetHeader>
                    <SheetTitle>Go to…</SheetTitle>
                </SheetHeader>
                <div className="flex flex-col gap-5 px-4 pt-1">
                    {groups.map((group) => (
                        <div key={group.label} className="flex flex-col gap-2">
                            <p className="eyebrow text-muted-foreground px-1">
                                {group.label}
                            </p>
                            <div className="grid grid-cols-2 gap-2">
                                {group.items.map((item) => {
                                    const active = isItemActive(item);
                                    const Icon = item.icon;

                                    return (
                                        <Link
                                            key={item.title}
                                            href={item.href}
                                            prefetch
                                            onClick={() => onOpenChange(false)}
                                            aria-current={
                                                active ? 'page' : undefined
                                            }
                                            className={cn(
                                                'flex items-center gap-3 rounded-xl border px-3.5 py-3 text-sm transition-colors',
                                                active
                                                    ? 'border-primary bg-primary/5 text-foreground font-medium'
                                                    : 'border-border bg-card hover:bg-muted',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'flex size-9 shrink-0 items-center justify-center rounded-full',
                                                    active
                                                        ? 'bg-primary text-primary-foreground'
                                                        : 'bg-accent text-accent-foreground',
                                                )}
                                            >
                                                {Icon && (
                                                    <Icon className="size-[18px]" />
                                                )}
                                            </span>
                                            <span className="min-w-0 flex-1 truncate">
                                                {item.title}
                                            </span>
                                            {item.number && (
                                                <span
                                                    aria-hidden
                                                    className="text-muted-foreground shrink-0 font-mono text-[10.5px] tabular-nums opacity-70"
                                                >
                                                    {item.number}
                                                </span>
                                            )}
                                        </Link>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
                </div>
            </SheetContent>
        </Sheet>
    );
}
