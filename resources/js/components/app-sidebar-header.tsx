import { Breadcrumbs } from '@/components/breadcrumbs';
import { NotificationBell } from '@/components/notification-bell';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

const dateStamp = new Intl.DateTimeFormat(undefined, {
    weekday: 'short',
    day: '2-digit',
    month: 'short',
});

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="border-border/70 bg-background/85 supports-[backdrop-filter]:bg-background/70 sticky top-0 z-20 flex h-14 shrink-0 items-center gap-3 border-b px-4 backdrop-blur-md md:rounded-t-[1.25rem] md:px-6">
            <SidebarTrigger className="text-muted-foreground hover:text-foreground -ml-1.5 rounded-full" />
            <span aria-hidden className="bg-border h-4 w-px" />
            <Breadcrumbs breadcrumbs={breadcrumbs} />
            <div className="ml-auto flex items-center gap-3">
                <span className="eyebrow text-muted-foreground hidden sm:inline">
                    {dateStamp.format(new Date())}
                </span>
                <NotificationBell />
            </div>
        </header>
    );
}
