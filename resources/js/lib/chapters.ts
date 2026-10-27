import type { LucideIcon } from 'lucide-react';
import {
    AtSign,
    Building2,
    CalendarDays,
    FileBarChart,
    Image,
    LayoutGrid,
    LineChart,
    MessageSquareHeart,
    Receipt,
    Send,
    Settings2,
    Sparkles,
    Sun,
    SwatchBook,
    UsersRound,
} from 'lucide-react';

/**
 * The app reads like the brand book: every module is a numbered chapter (book §00 "Contents"),
 * with its own opener swatch. The sidebar, `PageHeader`'s running head and the command palette all
 * read from this one map, so numbering never drifts between them.
 *
 * Swatches are decorative chapter identity only — never status (status lives in `lib/status.ts`).
 */
export type ChapterKey =
    | 'home'
    | 'calendar'
    | 'content'
    | 'inbox'
    | 'clients'
    | 'invoices'
    | 'analytics'
    | 'staff'
    | 'settings';

/**
 * Phase B-4: the business workspace shell is its own book (see `businessChapters`) — Today,
 * Calendar, Posts, Ideas, Media, Insights, Brand, Accounts, then Team/Settings — with no agency
 * clients, invoices or portal chapters.
 */
export type BusinessChapterKey =
    | 'today'
    | 'calendar'
    | 'posts'
    | 'inbox'
    | 'ideas'
    | 'media'
    | 'insights'
    | 'brand'
    | 'accounts'
    | 'team'
    | 'settings';

/** Phase 15: the super admin console is its own, shorter book (see `platformChapters`). */
export type PlatformChapterKey =
    | 'platformOverview'
    | 'organizations'
    | 'analytics'
    | 'reports'
    | 'partners'
    | 'platformSettings';

export type Chapter = {
    key: ChapterKey | PlatformChapterKey | BusinessChapterKey;
    number: string;
    title: string;
    /** One line for the command palette. */
    summary: string;
    icon: LucideIcon;
    /** Background utility for the chapter swatch (a small square, book §6.1 style). */
    swatch: string;
    /** URL prefixes (without the `/preview` mirror) that belong to this chapter. */
    prefixes: string[];
};

export const chapters: Record<ChapterKey, Chapter> = {
    // MOVE 1 (ux-overhaul): the single front door. The old Overview, Needs attention and My work
    // fold into one adaptive Home — `/dashboard` (the studio view), `/home` (the attention queue)
    // and `/my-work` (mine) all resolve here and are switched by `HomeViewTabs` in-page.
    home: {
        key: 'home',
        number: '01',
        title: 'Home',
        summary:
            'What needs you, what is going out, and how every client is tracking.',
        icon: LayoutGrid,
        swatch: 'bg-lime',
        prefixes: ['/dashboard', '/home', '/my-work'],
    },
    calendar: {
        key: 'calendar',
        number: '02',
        title: 'Calendar',
        summary: 'Every scheduled post, by month, week, day or list.',
        icon: CalendarDays,
        swatch: 'bg-electric',
        prefixes: ['/calendar'],
    },
    // MOVE 2/3 (ux-overhaul): the production engine, promoted out of Clients to its own chapter —
    // the content hub (posts), campaigns and grids, where the studio spends the day. Client-scoped
    // versions of these still live under `/clients/...` and resolve to the Clients chapter.
    content: {
        key: 'content',
        number: '03',
        title: 'Content',
        summary: 'Posts, campaigns and grids across every client.',
        icon: Send,
        // Teal on Ink is too low-contrast (book §3.6), so dark mode adds a cream ring.
        swatch: 'bg-teal dark:ring-1 dark:ring-inset dark:ring-cream/50',
        prefixes: [
            // Step M-5.1: the content hub (table + board across every client).
            '/content',
            '/posts',
            '/campaigns',
            '/campaign-templates',
            // Phase 18: the Instagram grid planner.
            '/grids',
            '/ai-trends',
            '/ai-post-ideas',
        ],
    },
    // Phase M-9: the social inbox — comments on the studio's published posts, triaged and replied to.
    // Its own chapter because community management is a daily surface and a headline selling point.
    inbox: {
        key: 'inbox',
        number: '04',
        title: 'Inbox',
        summary:
            'Comments across every client, triaged, assigned and answered.',
        icon: MessageSquareHeart,
        swatch: 'bg-electric',
        prefixes: ['/inbox'],
    },
    // MOVE 2/3: Clients is now purely the roster + per-client CRM (Brand Brain, accounts, billing,
    // briefs, portal access, health).
    clients: {
        key: 'clients',
        number: '05',
        title: 'Clients',
        summary: 'The roster — brands, Brand Brain, accounts and billing.',
        icon: Building2,
        swatch: 'bg-lilac',
        prefixes: ['/clients'],
    },
    invoices: {
        key: 'invoices',
        number: '06',
        title: 'Invoices',
        summary: 'The ledger — raised, sent, paid and overdue.',
        icon: Receipt,
        swatch: 'bg-ink dark:bg-cream',
        prefixes: ['/invoices'],
    },
    // Phase 17: the studio's own analytics. Every staff role sees it (sections are gated inside).
    analytics: {
        key: 'analytics',
        number: '07',
        title: 'Analytics',
        summary:
            'How the studio is tracking — output, approvals, billing and AI.',
        icon: LineChart,
        swatch: 'bg-electric',
        prefixes: ['/analytics'],
    },
    staff: {
        key: 'staff',
        number: '08',
        title: 'Staff',
        summary: 'The team, their roles and invitations.',
        icon: UsersRound,
        swatch: 'bg-signal',
        prefixes: ['/staff'],
    },
    settings: {
        key: 'settings',
        number: '09',
        title: 'Settings',
        summary: 'Profile, security, appearance and organization.',
        icon: Settings2,
        swatch: 'bg-cream ring-1 ring-inset ring-foreground/25',
        prefixes: ['/settings'],
    },
};

/**
 * Phase 15: the platform console (`/super-admin/*`) for org-less super admins. It reads as its
 * own book, numbered independently of the studio. Settings is shared with the studio by URL, so
 * a super admin's `/settings` pages resolve here instead (see `chapterForPath`'s `platform`).
 */
export const platformChapters: Record<PlatformChapterKey, Chapter> = {
    platformOverview: {
        key: 'platformOverview',
        number: '01',
        title: 'Overview',
        summary:
            'The platform at a glance — signups, activity, who needs a look.',
        icon: LayoutGrid,
        swatch: 'bg-lime',
        prefixes: ['/super-admin'],
    },
    organizations: {
        key: 'organizations',
        number: '02',
        title: 'Organizations',
        summary: 'Every workspace — onboard, edit, suspend or remove.',
        icon: Building2,
        swatch: 'bg-teal dark:ring-1 dark:ring-inset dark:ring-cream/50',
        prefixes: ['/super-admin/organizations'],
    },
    analytics: {
        key: 'analytics',
        number: '03',
        title: 'Analytics',
        summary: 'Platform-wide figures across every organization.',
        icon: LineChart,
        swatch: 'bg-electric',
        prefixes: ['/super-admin/analytics'],
    },
    reports: {
        key: 'reports',
        number: '04',
        title: 'Reports',
        summary: 'Saved analytics views you can re-run and export.',
        icon: FileBarChart,
        swatch: 'bg-lilac',
        prefixes: ['/super-admin/reports'],
    },
    partners: {
        key: 'partners',
        number: '05',
        title: 'Partners',
        summary: 'Design-partner usage and the in-app feedback inbox.',
        icon: MessageSquareHeart,
        swatch: 'bg-signal',
        prefixes: ['/super-admin/partners', '/super-admin/feedback'],
    },
    platformSettings: {
        key: 'platformSettings',
        number: '06',
        title: 'Settings',
        summary: 'Your profile, security and appearance.',
        icon: Settings2,
        swatch: 'bg-cream ring-1 ring-inset ring-foreground/25',
        prefixes: ['/settings'],
    },
};

/**
 * Phase B-4: the business workspace's book. Same Contents-style numbering as the studio, but a
 * calmer, brand-owner set — no clients, invoices, portal or staff-analytics chapters. "Collections"
 * (campaigns, renamed) is a section of Posts, so `/collections` resolves to the Posts chapter.
 */
export const businessChapters: Record<BusinessChapterKey, Chapter> = {
    today: {
        key: 'today',
        number: '01',
        title: 'Today',
        summary: "What's going out, what needs you, where the gaps are.",
        icon: Sun,
        swatch: 'bg-lime',
        prefixes: ['/today'],
    },
    calendar: {
        key: 'calendar',
        number: '02',
        title: 'Calendar',
        summary: 'Everything you have scheduled, by month, week or day.',
        icon: CalendarDays,
        swatch: 'bg-electric',
        prefixes: ['/calendar'],
    },
    posts: {
        key: 'posts',
        number: '03',
        title: 'Posts',
        summary:
            'Every post — drafts, scheduled and published — your grid and collections.',
        icon: Send,
        swatch: 'bg-teal dark:ring-1 dark:ring-inset dark:ring-cream/50',
        // Step CG-1.2: the Instagram grid planner lives under Posts (a section, like Collections).
        // `/grid` is the business hub; `/grids` is the shared planner/editor the hub opens into.
        prefixes: ['/posts', '/collections', '/grid', '/grids'],
    },
    // Step CG-1.1: the social inbox, switched on for the business shell — comments on the brand's
    // own posts, triaged and answered with a reply the owner always sends.
    inbox: {
        key: 'inbox',
        number: '04',
        title: 'Inbox',
        summary: 'Comments on your posts, triaged and ready for a reply.',
        icon: MessageSquareHeart,
        swatch: 'bg-electric',
        prefixes: ['/inbox'],
    },
    ideas: {
        key: 'ideas',
        number: '05',
        title: 'Ideas',
        summary: 'Fresh post ideas and trends for your brand.',
        icon: Sparkles,
        swatch: 'bg-lilac',
        prefixes: ['/ideas'],
    },
    media: {
        key: 'media',
        number: '06',
        title: 'Media',
        summary: 'Your photos and videos, ready to drop into a post.',
        icon: Image,
        swatch: 'bg-signal',
        // The Image Studio (B-6.5) lives under Media — generated images become media.
        prefixes: ['/media', '/studio'],
    },
    insights: {
        key: 'insights',
        number: '07',
        title: 'Insights',
        summary:
            'How your posting is tracking — consistency, reach and your best times.',
        icon: LineChart,
        swatch: 'bg-electric',
        prefixes: ['/insights'],
    },
    brand: {
        key: 'brand',
        number: '08',
        title: 'Brand',
        summary: 'Your voice, audience, look and posting goal.',
        icon: SwatchBook,
        swatch: 'bg-teal dark:ring-1 dark:ring-inset dark:ring-cream/50',
        prefixes: ['/brand'],
    },
    accounts: {
        key: 'accounts',
        number: '09',
        title: 'Accounts',
        summary: 'Connect and reconnect your social accounts.',
        icon: AtSign,
        swatch: 'bg-lime',
        prefixes: ['/accounts'],
    },
    team: {
        key: 'team',
        number: '10',
        title: 'Team',
        summary: 'The people you work with and their roles.',
        icon: UsersRound,
        swatch: 'bg-lilac',
        prefixes: ['/staff'],
    },
    settings: {
        key: 'settings',
        number: '11',
        title: 'Settings',
        summary: 'Profile, security, appearance, workspace and plan.',
        icon: Settings2,
        swatch: 'bg-cream ring-1 ring-inset ring-foreground/25',
        prefixes: ['/settings'],
    },
};

function matches(chapter: Chapter, path: string): boolean {
    return chapter.prefixes.some(
        (prefix) => path === prefix || path.startsWith(`${prefix}/`),
    );
}

/**
 * Resolves the chapter a URL path belongs to (the `/preview` mirror maps to the same chapter).
 * `platform` = the viewer is a super admin, so shared URLs (Settings) resolve to the console's book.
 */
export function chapterForPath(
    path: string,
    {
        platform = false,
        business = false,
    }: { platform?: boolean; business?: boolean } = {},
): Chapter | null {
    const normalised = path.replace(/^\/preview(?=\/|$)/, '').split('?')[0];

    if (platform || matches(platformChapters.platformOverview, normalised)) {
        // Most specific first: `/super-admin` itself is the overview's only prefix.
        const ordered = [
            platformChapters.organizations,
            platformChapters.analytics,
            platformChapters.reports,
            platformChapters.partners,
            platformChapters.platformSettings,
            platformChapters.platformOverview,
        ];

        return ordered.find((chapter) => matches(chapter, normalised)) ?? null;
    }

    if (business) {
        return (
            Object.values(businessChapters).find((chapter) =>
                matches(chapter, normalised),
            ) ?? null
        );
    }

    return (
        Object.values(chapters).find((chapter) =>
            matches(chapter, normalised),
        ) ?? null
    );
}
