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
import { chapters, platformChapters } from '@/lib/chapters';
import { calendar, dashboard, needsAttention } from '@/routes';
import { index as analyticsIndex } from '@/routes/analytics';
import { index as campaignsIndex } from '@/routes/campaigns';
import { index as clientsIndex } from '@/routes/clients';
import { index as invoicesIndex } from '@/routes/invoices';
import { edit as editProfile } from '@/routes/profile';
import { index as staffIndex } from '@/routes/staff';
import { dashboard as superAdminDashboard } from '@/routes/super-admin';
import { index as superAdminAnalytics } from '@/routes/super-admin/analytics';
import { index as superAdminOrganizations } from '@/routes/super-admin/organizations';
import { index as superAdminReports } from '@/routes/super-admin/reports';
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
    const isSuperAdmin = auth.user.role === 'super_admin';
    const onSettings = currentUrl
        .replace(/^\/preview/, '')
        .startsWith('/settings');

    // Phase 15: platform super admins have no organization — they get the console's own book
    // instead of the studio chapters (which `org.member` would redirect them away from anyway).
    const groups: { label: string; items: NavItem[] }[] = isSuperAdmin
        ? [
              {
                  label: 'Platform',
                  items: [
                      navItem(
                          platformChapters.platformOverview,
                          superAdminDashboard(),
                          currentUrl === '/super-admin',
                      ),
                      navItem(
                          platformChapters.organizations,
                          superAdminOrganizations(),
                      ),
                      navItem(
                          platformChapters.analytics,
                          superAdminAnalytics(),
                      ),
                      navItem(platformChapters.reports, superAdminReports()),
                  ],
              },
              {
                  label: 'Account',
                  items: [
                      navItem(
                          platformChapters.platformSettings,
                          editProfile(),
                          onSettings,
                      ),
                  ],
              },
          ]
        : studioGroups(auth, onSettings);

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
                            <Link
                                href={
                                    isSuperAdmin
                                        ? superAdminDashboard()
                                        : dashboard()
                                }
                                prefetch
                            >
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-3 overflow-x-hidden pt-1">
                {groups.map((group) => (
                    <NavMain
                        key={group.label}
                        items={group.items}
                        label={group.label}
                    />
                ))}
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

function studioGroups(
    auth: Auth,
    onSettings: boolean,
): { label: string; items: NavItem[] }[] {
    // Client portal contacts never render this shell (`AppLayout` swaps to `ClientPortalLayout`).
    // Billing roles only — see `InvoicePolicy::viewLedger`.
    const canViewLedger =
        auth.user.role === 'owner' || auth.user.role === 'strategist';

    return [
        {
            label: 'Studio',
            items: [
                navItem(chapters.overview, dashboard()),
                navItem(chapters.attention, needsAttention()),
                navItem(chapters.calendar, calendar()),
                {
                    ...navItem(chapters.clients, clientsIndex()),
                    // Campaigns enhancement C-1.1: the hub is a section of 04 Clients (no renumber).
                    subItems: [{ title: 'Campaigns', href: campaignsIndex() }],
                },
                ...(canViewLedger
                    ? [navItem(chapters.invoices, invoicesIndex())]
                    : []),
                // Phase 17 — `OrganizationPolicy::viewAnalytics`, shared as `auth.can`.
                ...(auth.can?.viewAnalytics
                    ? [navItem(chapters.analytics, analyticsIndex())]
                    : []),
            ],
        },
        {
            label: 'Team',
            items: [
                // Owner-only — see `StaffPolicy::viewAny`.
                ...(auth.user.role === 'owner'
                    ? [navItem(chapters.staff, staffIndex())]
                    : []),
                navItem(chapters.settings, editProfile(), onSettings),
            ],
        },
    ];
}
