import type { LucideIcon } from 'lucide-react';
import {
    Building2,
    CalendarDays,
    FileBarChart,
    LayoutGrid,
    LineChart,
    ListChecks,
    Receipt,
    Settings2,
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
    | 'overview'
    | 'attention'
    | 'calendar'
    | 'clients'
    | 'invoices'
    | 'staff'
    | 'settings';

/** Phase 15: the super admin console is its own, shorter book (see `platformChapters`). */
export type PlatformChapterKey =
    | 'platformOverview'
    | 'organizations'
    | 'analytics'
    | 'reports'
    | 'platformSettings';

export type Chapter = {
    key: ChapterKey | PlatformChapterKey;
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
    overview: {
        key: 'overview',
        number: '01',
        title: 'Overview',
        summary:
            'What is blocked, what is going out, how clients are tracking.',
        icon: LayoutGrid,
        swatch: 'bg-lime',
        prefixes: ['/dashboard'],
    },
    attention: {
        key: 'attention',
        number: '02',
        title: 'Needs attention',
        summary: 'Every decision waiting on the studio.',
        icon: ListChecks,
        swatch: 'bg-lilac',
        prefixes: ['/home'],
    },
    calendar: {
        key: 'calendar',
        number: '03',
        title: 'Calendar',
        summary: 'Every scheduled post, by month, week, day or list.',
        icon: CalendarDays,
        swatch: 'bg-electric',
        prefixes: ['/calendar'],
    },
    clients: {
        key: 'clients',
        number: '04',
        title: 'Clients',
        summary: 'The roster — brands, campaigns, posts and Brand Brain.',
        icon: Building2,
        // Teal on Ink is too low-contrast (book §3.6), so dark mode adds a cream ring.
        swatch: 'bg-teal dark:ring-1 dark:ring-inset dark:ring-cream/50',
        prefixes: [
            '/clients',
            '/campaigns',
            '/posts',
            '/ai-trends',
            '/ai-post-ideas',
        ],
    },
    invoices: {
        key: 'invoices',
        number: '05',
        title: 'Invoices',
        summary: 'The ledger — raised, sent, paid and overdue.',
        icon: Receipt,
        swatch: 'bg-ink dark:bg-cream',
        prefixes: ['/invoices'],
    },
    staff: {
        key: 'staff',
        number: '06',
        title: 'Staff',
        summary: 'The team, their roles and invitations.',
        icon: UsersRound,
        swatch: 'bg-lilac',
        prefixes: ['/staff'],
    },
    settings: {
        key: 'settings',
        number: '07',
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
    platformSettings: {
        key: 'platformSettings',
        number: '05',
        title: 'Settings',
        summary: 'Your profile, security and appearance.',
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
    { platform = false }: { platform?: boolean } = {},
): Chapter | null {
    const normalised = path.replace(/^\/preview(?=\/|$)/, '').split('?')[0];

    if (platform || matches(platformChapters.platformOverview, normalised)) {
        // Most specific first: `/super-admin` itself is the overview's only prefix.
        const ordered = [
            platformChapters.organizations,
            platformChapters.analytics,
            platformChapters.reports,
            platformChapters.platformSettings,
            platformChapters.platformOverview,
        ];

        return ordered.find((chapter) => matches(chapter, normalised)) ?? null;
    }

    return (
        Object.values(chapters).find((chapter) =>
            matches(chapter, normalised),
        ) ?? null
    );
}
