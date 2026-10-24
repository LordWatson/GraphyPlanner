import { Link, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { BrandMark } from '@/components/brand-mark';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { useCommandPalette } from '@/components/command-palette';
import { CreateMenu } from '@/components/create-menu';
import { NotificationBell } from '@/components/notification-bell';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { useNavGroups } from '@/hooks/use-nav-groups';
import type { Auth, BreadcrumbItem as BreadcrumbItemType } from '@/types';

const dateStamp = new Intl.DateTimeFormat(undefined, {
    weekday: 'short',
    day: '2-digit',
    month: 'short',
});

const isMac =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform);

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { open } = useCommandPalette();
    const { homeHref } = useNavGroups();
    const { auth } = usePage<{ auth: Auth }>().props;

    return (
        <header className="border-border/70 bg-background/85 supports-[backdrop-filter]:bg-background/70 sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b px-4 backdrop-blur-md md:rounded-t-2xl md:px-6">
            {/* Desktop: collapse the sidebar, then breadcrumbs. */}
            <SidebarTrigger className="text-muted-foreground hover:text-foreground -ml-1.5 hidden rounded-full md:inline-flex" />
            <span aria-hidden className="bg-border hidden h-4 w-px md:block" />

            {/* Phones: the sidebar is off-canvas, so the brand lives here instead. */}
            <Link
                href={homeHref}
                prefetch
                aria-label="Home"
                className="focus-visible:ring-ring/40 flex shrink-0 items-center rounded-full outline-none focus-visible:ring-[3px] md:hidden"
            >
                <BrandMark size="sm" />
            </Link>

            <div className="hidden min-w-0 md:block">
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>

            <div className="ml-auto flex items-center gap-1.5 sm:gap-3">
                <CreateMenu />
                <button
                    type="button"
                    onClick={open}
                    className="border-border bg-card text-muted-foreground hover:border-foreground/30 hover:text-foreground focus-visible:ring-ring/40 flex h-10 items-center gap-2.5 rounded-full border px-3 text-[13px] transition-colors outline-none focus-visible:ring-[3px] sm:w-64 sm:pr-2 sm:pl-3.5"
                    aria-label="Search and jump (Ctrl K)"
                >
                    <Search className="size-4 shrink-0" />
                    <span className="hidden flex-1 text-left sm:inline">
                        Search or jump to…
                    </span>
                    <kbd className="bg-muted hidden rounded-full px-2 py-0.5 font-mono text-[10.5px] tracking-wide sm:inline">
                        {isMac ? '⌘' : 'Ctrl'} K
                    </kbd>
                </button>
                <span className="eyebrow text-muted-foreground hidden lg:inline">
                    {dateStamp.format(new Date())}
                </span>
                <NotificationBell />

                {/* Phones: the account menu (Settings, log out) — on desktop it sits in the
                    sidebar footer, which the off-canvas sidebar hides on mobile. */}
                {auth.user && (
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            className="focus-visible:ring-ring/40 flex items-center rounded-full outline-none focus-visible:ring-[3px] md:hidden [&>div:last-child]:hidden"
                            aria-label="Account menu"
                        >
                            <UserInfo user={auth.user} />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="min-w-56">
                            <UserMenuContent user={auth.user} />
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>
        </header>
    );
}
