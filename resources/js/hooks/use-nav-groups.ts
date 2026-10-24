import { usePage } from '@inertiajs/react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useWorkspace } from '@/hooks/use-workspace';
import type { Chapter } from '@/lib/chapters';
import { businessChapters, chapters, platformChapters } from '@/lib/chapters';
import { calendar, dashboard } from '@/routes';
import { index as analyticsIndex } from '@/routes/analytics';
import { today as businessToday } from '@/routes/business';
import { index as businessAccounts } from '@/routes/business/accounts';
import { edit as businessBrand } from '@/routes/business/brand';
import { index as businessCollections } from '@/routes/business/collections';
import { index as businessIdeas } from '@/routes/business/ideas';
import { index as businessInsights } from '@/routes/business/insights';
import { index as businessMedia } from '@/routes/business/media';
import { index as businessPosts } from '@/routes/business/posts';
import { index as campaignsIndex } from '@/routes/campaigns';
import { index as clientsIndex } from '@/routes/clients';
import { index as contentIndex } from '@/routes/content';
import { index as gridsIndex } from '@/routes/grids';
import { index as inboxIndex } from '@/routes/inbox';
import { index as invoicesIndex } from '@/routes/invoices';
import { edit as editProfile } from '@/routes/profile';
import { index as staffIndex } from '@/routes/staff';
import { dashboard as superAdminDashboard } from '@/routes/super-admin';
import { index as superAdminAnalytics } from '@/routes/super-admin/analytics';
import { index as superAdminOrganizations } from '@/routes/super-admin/organizations';
import { index as superAdminPartners } from '@/routes/super-admin/partners';
import { index as superAdminReports } from '@/routes/super-admin/reports';
import type { Auth, NavItem } from '@/types';

/**
 * The navigation model, resolved once and shared by every shell surface — the teal sidebar on
 * desktop and the bottom tab bar + "More" sheet on phones (`components/mobile-nav.tsx`). Keeping
 * one source of truth means the two never drift on who sees which chapter.
 *
 * The groups mirror the brand book's Contents page: a super admin gets the platform console's book,
 * a business workspace its calmer owner book, and an agency the full studio book — each gated by
 * role/permission exactly as the policies allow.
 */
export type NavGroup = { label: string; items: NavItem[] };

export type NavModel = {
    /** The shell's home destination (the logo target). */
    homeHref: NavItem['href'];
    groups: NavGroup[];
};

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

export function useNavGroups(): NavModel {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { isBusiness } = useWorkspace();
    const { currentUrl } = useCurrentUrl();
    const isSuperAdmin = auth.user.role === 'super_admin';
    const onSettings = currentUrl
        .replace(/^\/preview/, '')
        .startsWith('/settings');

    if (isSuperAdmin) {
        return {
            homeHref: superAdminDashboard(),
            groups: [
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
                        navItem(
                            platformChapters.partners,
                            superAdminPartners(),
                        ),
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
            ],
        };
    }

    if (isBusiness) {
        return {
            homeHref: businessToday(),
            groups: businessGroups(auth, onSettings),
        };
    }

    return { homeHref: dashboard(), groups: studioGroups(auth, onSettings) };
}

function studioGroups(auth: Auth, onSettings: boolean): NavGroup[] {
    // Billing roles only — see `InvoicePolicy::viewLedger`.
    const canViewLedger =
        auth.user.role === 'owner' || auth.user.role === 'strategist';

    return [
        {
            label: 'Studio',
            items: [
                navItem(chapters.home, dashboard()),
                navItem(chapters.calendar, calendar()),
                {
                    ...navItem(chapters.content, contentIndex()),
                    subItems: [
                        { title: 'Posts', href: contentIndex() },
                        { title: 'Campaigns', href: campaignsIndex() },
                        { title: 'Grids', href: gridsIndex() },
                    ],
                },
                navItem(chapters.inbox, inboxIndex()),
                navItem(chapters.clients, clientsIndex()),
                ...(canViewLedger
                    ? [navItem(chapters.invoices, invoicesIndex())]
                    : []),
                ...(auth.can?.viewAnalytics
                    ? [navItem(chapters.analytics, analyticsIndex())]
                    : []),
            ],
        },
        {
            label: 'Team',
            items: [
                ...(auth.user.role === 'owner'
                    ? [navItem(chapters.staff, staffIndex())]
                    : []),
                navItem(chapters.settings, editProfile(), onSettings),
            ],
        },
    ];
}

function businessGroups(auth: Auth, onSettings: boolean): NavGroup[] {
    return [
        {
            label: 'Your brand',
            items: [
                navItem(businessChapters.today, businessToday()),
                navItem(businessChapters.calendar, calendar()),
                {
                    ...navItem(businessChapters.posts, businessPosts()),
                    subItems: [
                        { title: 'Collections', href: businessCollections() },
                    ],
                },
                navItem(businessChapters.ideas, businessIdeas()),
                navItem(businessChapters.media, businessMedia()),
                navItem(businessChapters.insights, businessInsights()),
                navItem(businessChapters.brand, businessBrand()),
                navItem(businessChapters.accounts, businessAccounts()),
            ],
        },
        {
            label: 'Team',
            items: [
                ...(auth.user.role === 'owner'
                    ? [navItem(businessChapters.team, staffIndex())]
                    : []),
                navItem(businessChapters.settings, editProfile(), onSettings),
            ],
        },
    ];
}
