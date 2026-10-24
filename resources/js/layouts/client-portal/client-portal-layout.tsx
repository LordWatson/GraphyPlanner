import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    CalendarDays,
    CheckSquare,
    ChevronDown,
    Clapperboard,
    FileBarChart,
    Grid3x3,
    LayoutGrid,
    MoreHorizontal,
    Receipt,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';

import { BrandMark } from '@/components/brand-mark';
import { NotificationBell } from '@/components/notification-bell';
import { ShellPaddedContext } from '@/components/page';
import { PreviewBanner } from '@/components/preview-banner';
import { PwaProvider } from '@/components/pwa/pwa-provider';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { cn } from '@/lib/utils';
import {
    calendar as portalCalendar,
    company as portalCompany,
    dashboard as portalDashboard,
} from '@/routes/portal';
import { index as portalCampaignsIndex } from '@/routes/portal/campaigns';
import { index as portalGridsIndex } from '@/routes/portal/grids';
import { index as portalInvoicesIndex } from '@/routes/portal/invoices';
import { index as portalPostsIndex } from '@/routes/portal/posts';
import { index as portalReportsIndex } from '@/routes/portal/reports';
import type { Auth } from '@/types';

type PortalSharedProps = {
    auth: Auth;
    client: { name: string } | null;
    portal: {
        pendingApprovals: number;
        /** The managing agency (the reviewer's organization) — shown on every page. */
        agency?: { name: string } | null;
        grids?: { visible: number; waiting: number };
    } | null;
};

type NavItem = {
    title: string;
    href: ReturnType<typeof portalDashboard>;
    icon: LucideIcon;
    badge?: number;
};

/**
 * The client portal's shell — the *only* chrome a `Role::ClientReviewer` ever sees (internal routes
 * redirect them here via `RedirectClientReviewersToPortal`, and `AppLayout` swaps to this shell for
 * them on shared pages such as Settings).
 *
 * Built mobile-first: a client lands here from a WhatsApp notification on their phone, so the shell
 * is an app-like experience — a fixed bottom tab bar for thumb navigation on phones, the top pill
 * nav on larger screens. The managing agency's name leads the header on every page (per the brief,
 * the client must always see who their agency is), carried by the Alima app-icon mark for the
 * product brand. Per guidelines §7 it stays the lighter, client-facing variant: no ink sidebar.
 */
export default function ClientPortalLayout({
    children,
}: {
    children: ReactNode;
}) {
    const page = usePage<PortalSharedProps>();
    const { auth, client, portal } = page.props;
    const currentPath = page.url.split('?')[0];
    const pending = portal?.pendingApprovals ?? 0;
    const agencyName = portal?.agency?.name ?? null;
    const [moreOpen, setMoreOpen] = useState(false);

    const navItems: NavItem[] = [
        { title: 'Overview', href: portalDashboard(), icon: LayoutGrid },
        {
            title: 'Posts',
            href: portalPostsIndex(),
            icon: CheckSquare,
            badge: pending,
        },
        { title: 'Calendar', href: portalCalendar(), icon: CalendarDays },
        {
            title: 'Campaigns',
            href: portalCampaignsIndex(),
            icon: Clapperboard,
        },
        // Phase 18: only once the studio has sent the client a grid.
        ...((portal?.grids?.visible ?? 0) > 0
            ? [
                  {
                      title: 'Grids',
                      href: portalGridsIndex(),
                      icon: Grid3x3,
                      badge: portal?.grids?.waiting ?? 0,
                  },
              ]
            : []),
        // Step M-3.3: the studio's monthly reports.
        { title: 'Reports', href: portalReportsIndex(), icon: FileBarChart },
        { title: 'Invoices', href: portalInvoicesIndex(), icon: Receipt },
        { title: 'Company', href: portalCompany(), icon: Building2 },
    ];

    const isActive = (href: { url: string }) => {
        const itemPath = href.url.split('?')[0];

        return itemPath === portalDashboard().url
            ? currentPath === itemPath
            : currentPath === itemPath ||
                  currentPath.startsWith(`${itemPath}/`);
    };

    // The four destinations a client reaches for most sit in the bottom bar; a "More" tab opens the
    // rest in a sheet. Any item not on the bar (incl. a live Grids tab) still lives in "More".
    const barTitles = ['Overview', 'Posts', 'Calendar', 'Invoices'];
    const barItems = barTitles
        .map((title) => navItems.find((item) => item.title === title))
        .filter((item): item is NavItem => item !== undefined);
    const moreActive = navItems.some(
        (item) => !barTitles.includes(item.title) && isActive(item.href),
    );

    return (
        <div className="bg-background text-foreground relative flex min-h-screen flex-col">
            <PreviewBanner />
            <header className="border-border/70 bg-background/85 supports-[backdrop-filter]:bg-background/70 sticky top-0 z-30 border-b backdrop-blur-md">
                {/* Alima brand accent — the spectrum as a hairline band (§3.8: transitions only). */}
                <div aria-hidden className="bg-spectrum h-1 w-full" />
                <div className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 pt-3.5 pb-3 sm:px-6">
                    <Link
                        href={portalDashboard()}
                        aria-label="Portal home"
                        className="group flex min-w-0 items-center gap-3"
                    >
                        <BrandMark size="sm" />
                        <span className="flex min-w-0 flex-col leading-tight">
                            <span className="eyebrow text-muted-foreground text-[9.5px] whitespace-nowrap">
                                {agencyName ? 'Your agency' : 'Client portal'}
                            </span>
                            <span className="group-hover:text-primary truncate text-[15px] font-semibold tracking-[-0.01em] transition-colors">
                                {agencyName ?? 'Alima'}
                            </span>
                        </span>
                    </Link>

                    <div className="flex shrink-0 items-center gap-2">
                        {client && (
                            <span className="border-border bg-card hidden items-center gap-1.5 rounded-full border py-1 pr-3 pl-1.5 sm:inline-flex">
                                <span className="bg-lilac text-ink font-display flex size-6 items-center justify-center rounded-full text-xs leading-none">
                                    {client.name.charAt(0)}
                                </span>
                                <span className="max-w-[12rem] truncate text-xs font-medium">
                                    {client.name}
                                </span>
                            </span>
                        )}
                        <NotificationBell />
                        {auth.user && (
                            <DropdownMenu>
                                <DropdownMenuTrigger
                                    className="border-border bg-card hover:bg-muted focus-visible:ring-ring/40 flex items-center gap-2 rounded-full border py-1 pr-2.5 pl-1 text-sm outline-none focus-visible:ring-[3px]"
                                    data-test="portal-user-menu"
                                >
                                    <span className="flex items-center gap-2 [&>div:last-child]:hidden sm:[&>div:last-child]:grid">
                                        <UserInfo user={auth.user} />
                                    </span>
                                    <ChevronDown className="text-muted-foreground size-3.5" />
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="end"
                                    className="min-w-60"
                                >
                                    <UserMenuContent user={auth.user} />
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>
                </div>

                {/* Desktop: the full pill nav. Phones use the fixed bottom bar instead. */}
                <nav
                    aria-label="Client portal"
                    className="mx-auto hidden w-full max-w-6xl gap-1 px-4 pb-3 sm:px-6 md:flex md:flex-wrap"
                >
                    {navItems.map((item) => (
                        <PillLink
                            key={item.title}
                            item={item}
                            active={isActive(item.href)}
                        />
                    ))}
                </nav>
            </header>

            <main className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 px-4 pt-6 pb-28 sm:px-6 md:pt-10 md:pb-12">
                <ShellPaddedContext.Provider value={true}>
                    {children}
                </ShellPaddedContext.Provider>
            </main>

            <footer className="eyebrow text-muted-foreground mx-auto hidden w-full max-w-6xl justify-between gap-4 px-4 py-6 sm:px-6 md:flex">
                <span>
                    {agencyName
                        ? `${agencyName} · client portal`
                        : 'Your client portal'}
                </span>
                <span>Powered by Alima — marketing brighter together.</span>
            </footer>

            {/* Phones: a fixed, thumb-friendly bottom tab bar — the four core destinations plus a
                "More" tab for the rest. The client arrives from a WhatsApp tap, so this is the
                primary way they move around. */}
            <nav
                aria-label="Client portal"
                className="border-border/70 bg-background/95 supports-[backdrop-filter]:bg-background/80 fixed inset-x-0 bottom-0 z-40 border-t pb-[env(safe-area-inset-bottom)] backdrop-blur-md md:hidden"
            >
                <div className="mx-auto grid max-w-md grid-cols-5">
                    {barItems.map((item) => (
                        <TabLink
                            key={item.title}
                            item={item}
                            active={isActive(item.href)}
                        />
                    ))}
                    <button
                        type="button"
                        onClick={() => setMoreOpen(true)}
                        aria-current={moreActive ? 'page' : undefined}
                        className={cn(
                            'flex min-h-[52px] flex-col items-center justify-center gap-1 px-1 pt-2 pb-1.5 text-[10px] font-medium transition-colors',
                            moreActive
                                ? 'text-primary'
                                : 'text-muted-foreground',
                        )}
                    >
                        <MoreHorizontal className="size-[22px]" />
                        More
                    </button>
                </div>
            </nav>

            <Sheet open={moreOpen} onOpenChange={setMoreOpen}>
                <SheetContent
                    side="bottom"
                    className="rounded-t-2xl pb-[max(1.5rem,env(safe-area-inset-bottom))]"
                >
                    <SheetHeader>
                        <SheetTitle>
                            {agencyName
                                ? `${agencyName} · client portal`
                                : 'Client portal'}
                        </SheetTitle>
                    </SheetHeader>
                    <div className="grid grid-cols-2 gap-2 px-4 pt-1">
                        {navItems.map((item) => {
                            const active = isActive(item.href);

                            return (
                                <Link
                                    key={item.title}
                                    href={item.href}
                                    prefetch
                                    onClick={() => setMoreOpen(false)}
                                    aria-current={active ? 'page' : undefined}
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
                                        <item.icon className="size-[18px]" />
                                    </span>
                                    <span className="min-w-0 flex-1 truncate">
                                        {item.title}
                                    </span>
                                    {item.badge ? (
                                        <span className="bg-primary text-primary-foreground flex h-5 min-w-5 items-center justify-center rounded-full px-1 font-mono text-[10px] tabular-nums">
                                            {item.badge}
                                        </span>
                                    ) : null}
                                </Link>
                            );
                        })}
                    </div>
                </SheetContent>
            </Sheet>

            {/* Step M-6.6 (I-8.6): the portal is an installable PWA — add it to the home screen to
                review and approve on the go. Scoped to /portal via its own manifest. */}
            <PwaProvider
                manifestHref="/portal.webmanifest"
                title="Add the portal to your phone"
                description="Install it to review and approve your content in a tap."
            />
        </div>
    );
}

/** A top-nav pill (desktop). */
function PillLink({ item, active }: { item: NavItem; active: boolean }) {
    return (
        <Link
            href={item.href}
            prefetch
            aria-current={active ? 'page' : undefined}
            className={cn(
                'inline-flex shrink-0 items-center gap-2 rounded-full px-3.5 py-1.5 text-sm transition-colors',
                active
                    ? 'bg-ink text-ink-foreground dark:bg-foreground dark:text-background font-medium'
                    : 'text-muted-foreground hover:bg-muted hover:text-foreground',
            )}
        >
            <item.icon className="size-4" />
            {item.title}
            {item.badge ? (
                <span
                    className="bg-primary text-primary-foreground flex h-[18px] min-w-[18px] items-center justify-center rounded-full px-1 font-mono text-[10px] tabular-nums"
                    aria-label={`${item.badge} awaiting your approval`}
                >
                    {item.badge}
                </span>
            ) : null}
        </Link>
    );
}

/** A bottom-bar tab (phones): icon over label, with a badge dot for pending approvals. */
function TabLink({ item, active }: { item: NavItem; active: boolean }) {
    return (
        <Link
            href={item.href}
            prefetch
            aria-current={active ? 'page' : undefined}
            className={cn(
                'relative flex min-h-[52px] flex-col items-center justify-center gap-1 px-1 pt-2 pb-1.5 text-[10px] font-medium transition-colors',
                active ? 'text-primary' : 'text-muted-foreground',
            )}
        >
            <span className="relative">
                <item.icon className="size-[22px]" />
                {item.badge ? (
                    <span
                        className="bg-primary text-primary-foreground absolute -top-1.5 -right-2 flex h-[16px] min-w-[16px] items-center justify-center rounded-full px-1 font-mono text-[9px] leading-none tabular-nums"
                        aria-label={`${item.badge} awaiting your approval`}
                    >
                        {item.badge}
                    </span>
                ) : null}
            </span>
            {item.title}
        </Link>
    );
}
