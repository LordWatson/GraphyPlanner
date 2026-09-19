import { Link, usePage } from '@inertiajs/react';
import { BookOpen, Building2, CalendarDays, FolderGit2, LayoutGrid, ListChecks, UsersRound, UserSquare2 } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
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
import { calendar, dashboard, needsAttention } from '@/routes';
import { index as clientsIndex } from '@/routes/clients';
import { dashboard as portalDashboard } from '@/routes/portal';
import { index as staffIndex } from '@/routes/staff';
import type { Auth, NavItem } from '@/types';

// `Role::ClientReviewer` cannot use these org-internal items (see `ClientPolicy::viewAny` and
// `PostPolicy::viewHome`) — they get a link into their own client portal instead.
const clientReviewerNavItems: NavItem[] = [
    {
        title: 'Home',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Calendar',
        href: calendar(),
        icon: CalendarDays,
    },
    {
        title: 'Client portal',
        href: portalDashboard(),
        icon: UserSquare2,
    },
];

const mainNavItems: NavItem[] = [
    {
        title: 'Home',
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

const footerNavItems: NavItem[] = [
    /*{
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },*/
];

export function AppSidebar() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const items =
        auth.user.role === 'client_reviewer'
            ? clientReviewerNavItems
            : auth.user.role === 'owner'
              ? [...mainNavItems, staffNavItem]
              : mainNavItems;

    return (
        <Sidebar collapsible="icon">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
