import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    CalendarDays,
    LayoutGrid,
    ListChecks,
    Settings2,
    UsersRound,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
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
    SidebarSeparator,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { calendar, dashboard, needsAttention } from '@/routes';
import { index as clientsIndex } from '@/routes/clients';
import { edit as editProfile } from '@/routes/profile';
import { index as staffIndex } from '@/routes/staff';
import type { Auth, NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Overview',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Clients',
        href: clientsIndex(),
        icon: Building2,
    },
    {
        title: 'Calendar',
        href: calendar(),
        icon: CalendarDays,
    },
    {
        title: 'Needs attention',
        href: needsAttention(),
        icon: ListChecks,
    },
];

// Owner-only — see `StaffPolicy::viewAny`.
const staffNavItem: NavItem = {
    title: 'Staff',
    href: staffIndex(),
    icon: UsersRound,
};

export function AppSidebar() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { currentUrl } = useCurrentUrl();

    // Client portal contacts never render this shell (`AppLayout` swaps to `ClientPortalLayout`).
    const items = mainNavItems;

    const accountItems: NavItem[] = [
        ...(auth.user.role === 'owner' ? [staffNavItem] : []),
        {
            title: 'Settings',
            href: editProfile(),
            icon: Settings2,
            isActive: currentUrl.startsWith('/settings'),
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="px-3 pt-4 pb-2">
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

            <SidebarContent className="overflow-x-hidden pt-2">
                <NavMain items={items} label="Studio" />
                <SidebarSeparator className="bg-sidebar-border/70 mx-5 w-auto group-data-[collapsible=icon]:mx-2" />
                <NavMain items={accountItems} label="Team" />
            </SidebarContent>

            <SidebarFooter className="p-3">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
