import { Search } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { useCommandPalette } from '@/components/command-palette';
import { CreateMenu } from '@/components/create-menu';
import { NotificationBell } from '@/components/notification-bell';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

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

    return (
        <header className="border-border/70 bg-background/85 supports-[backdrop-filter]:bg-background/70 sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b px-4 backdrop-blur-md md:rounded-t-2xl md:px-6">
            <SidebarTrigger className="text-muted-foreground hover:text-foreground -ml-1.5 rounded-full" />
            <span aria-hidden className="bg-border h-4 w-px" />
            <Breadcrumbs breadcrumbs={breadcrumbs} />
            <div className="ml-auto flex items-center gap-2 sm:gap-3">
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
            </div>
        </header>
    );
}
