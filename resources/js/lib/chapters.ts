import type { LucideIcon } from 'lucide-react';
import {
    Building2,
    CalendarDays,
    LayoutGrid,
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

export type Chapter = {
    key: ChapterKey;
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

/** Resolves the chapter a URL path belongs to (the `/preview` mirror maps to the same chapter). */
export function chapterForPath(path: string): Chapter | null {
    const normalised = path.replace(/^\/preview(?=\/|$)/, '').split('?')[0];

    for (const chapter of Object.values(chapters)) {
        if (
            chapter.prefixes.some(
                (prefix) =>
                    normalised === prefix ||
                    normalised.startsWith(`${prefix}/`),
            )
        ) {
            return chapter;
        }
    }

    return null;
}
