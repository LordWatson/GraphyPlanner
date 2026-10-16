import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    CalendarDays,
    CheckSquare,
    ChevronDown,
    Clapperboard,
    LayoutGrid,
    Receipt,
} from 'lucide-react';
import type { ReactNode } from 'react';

import { BrandMark } from '@/components/brand-mark';
import { NotificationBell } from '@/components/notification-bell';
import { ShellPaddedContext } from '@/components/page';
import { PreviewBanner } from '@/components/preview-banner';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { cn } from '@/lib/utils';
import {
    calendar as portalCalendar,
    company as portalCompany,
    dashboard as portalDashboard,
} from '@/routes/portal';
import { index as portalCampaignsIndex } from '@/routes/portal/campaigns';
import { index as portalInvoicesIndex } from '@/routes/portal/invoices';
import { index as portalPostsIndex } from '@/routes/portal/posts';
import type { Auth } from '@/types';

type PortalSharedProps = {
    auth: Auth;
    client: { name: string } | null;
    portal: { pendingApprovals: number } | null;
};

/**
 * The client portal's shell — the *only* chrome a `Role::ClientReviewer` ever sees (internal routes
 * redirect them here via `RedirectClientReviewersToPortal`, and `AppLayout` swaps to this shell for
 * them on shared pages such as Settings). Per brand guidelines §7 it is the lighter, client-facing
 * variant of the system: no ink sidebar, only this client's identity, a pill nav (with the
 * pending-approvals badge from the shared `portal` prop), notifications, and the account menu.
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

    const navItems = [
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

    return (
        <div className="bg-background text-foreground relative flex min-h-screen flex-col">
            <PreviewBanner />
            <header className="border-border/70 bg-background/85 supports-[backdrop-filter]:bg-background/70 sticky top-0 z-30 border-b backdrop-blur-md">
                <div className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 pt-4 pb-3 sm:px-6">
                    <div className="flex min-w-0 items-center gap-3">
                        <Link href={portalDashboard()} aria-label="Portal home">
                            <BrandMark
                                size="sm"
                                wordmark
                                className="[&>span:last-child]:hidden sm:[&>span:last-child]:flex"
                            />
                        </Link>
                        {client && (
                            <>
                                <span
                                    aria-hidden
                                    className="bg-border h-6 w-px shrink-0"
                                />
                                <span className="flex min-w-0 flex-col leading-tight">
                                    <span className="eyebrow text-muted-foreground text-[9.5px] whitespace-nowrap">
                                        Client portal
                                    </span>
                                    <span className="truncate text-sm font-medium">
                                        {client.name}
                                    </span>
                                </span>
                            </>
                        )}
                    </div>

                    <div className="flex shrink-0 items-center gap-2">
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

                <nav
                    aria-label="Client portal"
                    className="mx-auto flex w-full max-w-6xl [scrollbar-width:none] gap-1 overflow-x-auto px-4 pb-3 sm:px-6"
                >
                    {navItems.map((item) => {
                        const active = isActive(item.href);

                        return (
                            <Link
                                key={item.title}
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
                    })}
                </nav>
            </header>

            <main className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 px-4 py-8 sm:px-6 md:py-10">
                <ShellPaddedContext.Provider value={true}>
                    {children}
                </ShellPaddedContext.Provider>
            </main>

            <footer className="eyebrow text-muted-foreground mx-auto flex w-full max-w-6xl justify-between gap-4 px-4 py-6 sm:px-6">
                <span>© {new Date().getFullYear()} Alima</span>
                <span>Your studio&apos;s client portal</span>
            </footer>
        </div>
    );
}
