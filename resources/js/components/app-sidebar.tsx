import { Link, usePage } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { Peek } from '@/components/brand-poster';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { Chapter } from '@/lib/chapters';
import { chapters } from '@/lib/chapters';
import { calendar, dashboard, needsAttention } from '@/routes';
import { index as clientsIndex } from '@/routes/clients';
import { index as invoicesIndex } from '@/routes/invoices';
import { edit as editProfile } from '@/routes/profile';
import { index as staffIndex } from '@/routes/staff';
import type { Auth, NavItem } from '@/types';

function navItem(
    chapter: Chapter,
    href: NavItem['href'],
    isActive?: boolean,
): NavItem {
    return {
        title: chapter.title,
        href,
        icon: chapter.icon,
        number: chapter.number,
        isActive,
    };
}

export function AppSidebar() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { currentUrl } = useCurrentUrl();

    // Client portal contacts never render this shell (`AppLayout` swaps to `ClientPortalLayout`).
    // Billing roles only — see `InvoicePolicy::viewLedger`.
    const canViewLedger =
        auth.user.role === 'owner' || auth.user.role === 'strategist';

    const studioItems: NavItem[] = [
        navItem(chapters.overview, dashboard()),
        navItem(chapters.attention, needsAttention()),
        navItem(chapters.calendar, calendar()),
        navItem(chapters.clients, clientsIndex()),
        ...(canViewLedger ? [navItem(chapters.invoices, invoicesIndex())] : []),
    ];

    const teamItems: NavItem[] = [
        // Owner-only — see `StaffPolicy::viewAny`.
        ...(auth.user.role === 'owner'
            ? [navItem(chapters.staff, staffIndex())]
            : []),
        navItem(
            chapters.settings,
            editProfile(),
            currentUrl.replace(/^\/preview/, '').startsWith('/settings'),
        ),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="px-3 pt-4 pb-3">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            asChild
                            className="hover:bg-transparent active:bg-transparent"
                        >
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-3 overflow-x-hidden pt-1">
                <NavMain items={studioItems} label="Studio" />
                <NavMain items={teamItems} label="Team" />
            </SidebarContent>

            <SidebarFooter className="gap-3 p-3">
                {/* The peek (§5.5): tonal teal-on-teal, peeking over a hairline — never behind text. */}
                <div
                    aria-hidden
                    className="border-sidebar-border relative mx-2 h-24 overflow-hidden border-b group-data-[collapsible=icon]:hidden"
                >
                    <Peek className="text-sidebar-accent left-1/2 w-[78%] -translate-x-1/2 translate-y-[38%]" />
                </div>
                <p className="eyebrow text-sidebar-foreground/65 px-2 pb-1 group-data-[collapsible=icon]:hidden">
                    Marketing brighter together.
                </p>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
