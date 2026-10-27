import { router, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    ClipboardCheck,
    CornerDownLeft,
    LayoutGrid,
    ListChecks,
    Megaphone,
    Moon,
    Search,
    Sun,
} from 'lucide-react';
import type { ReactNode } from 'react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useAppearance } from '@/hooks/use-appearance';
import { useWorkspace } from '@/hooks/use-workspace';
import type { Chapter } from '@/lib/chapters';
import { businessChapters, chapters, platformChapters } from '@/lib/chapters';
import { useCreateActions } from '@/lib/create-actions';
import { cn, toUrl } from '@/lib/utils';
import { calendar, dashboard, needsAttention } from '@/routes';
import { today as businessToday } from '@/routes/business';
import { index as businessAccounts } from '@/routes/business/accounts';
import { edit as businessBrand } from '@/routes/business/brand';
import { index as businessCollections } from '@/routes/business/collections';
import { index as businessGrids } from '@/routes/business/grids';
import { index as businessIdeas } from '@/routes/business/ideas';
import { index as businessInsights } from '@/routes/business/insights';
import { index as businessMedia } from '@/routes/business/media';
import { index as businessPosts } from '@/routes/business/posts';
import { index as clientsIndex } from '@/routes/clients';
import { index as analyticsIndex } from '@/routes/analytics';
import { index as campaignsIndex } from '@/routes/campaigns';
import { index as contentIndex } from '@/routes/content';
import { index as inboxIndex } from '@/routes/inbox';
import { index as gridsIndex } from '@/routes/grids';
import { index as myWorkIndex } from '@/routes/my-work';
import { index as invoicesIndex } from '@/routes/invoices';
import { edit as editProfile } from '@/routes/profile';
import { index as staffIndex } from '@/routes/staff';
import { dashboard as superAdminDashboard } from '@/routes/super-admin';
import { index as superAdminAnalytics } from '@/routes/super-admin/analytics';
import { index as superAdminOrganizations } from '@/routes/super-admin/organizations';
import { index as superAdminReports } from '@/routes/super-admin/reports';
import type { Auth } from '@/types';

type Command = {
    id: string;
    group: 'Chapters' | 'Create' | 'Appearance';
    label: string;
    hint?: string;
    keywords?: string;
    icon: LucideIcon;
    chapter?: Chapter;
    run: () => void;
};

const CommandPaletteContext = createContext<{ open: () => void }>({
    open: () => {},
});

/** Opens the ⌘K palette from anywhere inside the studio shell. */
export function useCommandPalette() {
    return useContext(CommandPaletteContext);
}

/**
 * The studio's ⌘K / Ctrl+K command palette: jump to any chapter, create, or switch theme without
 * leaving the keyboard. Mounted once by the sidebar shell; the header's search pill opens it too.
 */
export function CommandPaletteProvider({ children }: { children: ReactNode }) {
    const [isOpen, setIsOpen] = useState(false);
    const open = useCallback(() => setIsOpen(true), []);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (
                event.key.toLowerCase() === 'k' &&
                (event.metaKey || event.ctrlKey)
            ) {
                event.preventDefault();
                setIsOpen((current) => !current);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    const value = useMemo(() => ({ open }), [open]);

    return (
        <CommandPaletteContext.Provider value={value}>
            {children}
            <CommandPalette open={isOpen} onOpenChange={setIsOpen} />
        </CommandPaletteContext.Provider>
    );
}

function useCommands(close: () => void): Command[] {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { isBusiness } = useWorkspace();
    const { resolvedAppearance, updateAppearance } = useAppearance();
    // MOVE 4 (ux-overhaul): the Create group is the shared `useCreateActions` set, so the palette
    // and the header's New menu never drift.
    const createActions = useCreateActions();

    return useMemo(() => {
        const visit = (href: Parameters<typeof toUrl>[0]) => () => {
            close();
            router.visit(toUrl(href));
        };

        // Mirrors the sidebar: billing + client creation for owner/strategist
        // (`InvoicePolicy::viewLedger`, `ClientPolicy::create`), staff for owners (`StaffPolicy`).
        const isBilling =
            auth.user.role === 'owner' || auth.user.role === 'strategist';
        const isOwner = auth.user.role === 'owner';
        const canViewAnalytics = auth.can?.viewAnalytics === true;

        const chapterCommand = (
            chapter: Chapter,
            href: Parameters<typeof toUrl>[0],
        ): Command => ({
            id: `chapter-${chapter.key}`,
            group: 'Chapters',
            label: chapter.title,
            hint: chapter.summary,
            icon: chapter.icon,
            chapter,
            run: visit(href),
        });

        const studioCommands: Command[] = [
            chapterCommand(chapters.home, dashboard()),
            {
                id: 'section-needs-attention',
                group: 'Chapters',
                label: 'Needs attention',
                hint: 'Every decision waiting on the studio.',
                keywords: 'queue blocked triage decisions attention needs',
                icon: ListChecks,
                chapter: chapters.home,
                run: visit(needsAttention()),
            },
            {
                id: 'section-my-work',
                group: 'Chapters',
                label: 'My work',
                hint: 'Your posts: assigned, to review, due soon, mentioned.',
                keywords: 'my work assigned mine review due mentioned tasks',
                icon: ClipboardCheck,
                chapter: chapters.home,
                run: visit(myWorkIndex()),
            },
            chapterCommand(chapters.calendar, calendar()),
            chapterCommand(chapters.content, contentIndex()),
            {
                id: 'section-campaigns',
                group: 'Chapters',
                label: 'Campaigns',
                hint: 'Every campaign, in one place.',
                keywords: 'launch brief plan board',
                icon: Megaphone,
                chapter: chapters.content,
                run: visit(campaignsIndex()),
            },
            {
                id: 'section-grids',
                group: 'Chapters',
                label: 'Grids',
                hint: 'Plan each client’s Instagram grid.',
                keywords: 'instagram grid profile plan preview',
                icon: LayoutGrid,
                chapter: chapters.content,
                run: visit(gridsIndex()),
            },
            chapterCommand(chapters.inbox, inboxIndex()),
            chapterCommand(chapters.clients, clientsIndex()),
            ...(isBilling
                ? [chapterCommand(chapters.invoices, invoicesIndex())]
                : []),
            // Phase 17 — `OrganizationPolicy::viewAnalytics`, shared as `auth.can`.
            ...(canViewAnalytics
                ? [chapterCommand(chapters.analytics, analyticsIndex())]
                : []),
            ...(isOwner ? [chapterCommand(chapters.staff, staffIndex())] : []),
            chapterCommand(chapters.settings, editProfile()),
        ];

        // Phase B-4: a business workspace gets its own, calmer set of destinations.
        const businessCommands: Command[] = [
            chapterCommand(businessChapters.today, businessToday()),
            chapterCommand(businessChapters.calendar, calendar()),
            chapterCommand(businessChapters.posts, businessPosts()),
            {
                id: 'section-collections',
                group: 'Chapters',
                label: 'Collections',
                hint: 'Group related posts — a launch, a season.',
                keywords: 'campaign collection group launch season',
                icon: Megaphone,
                chapter: businessChapters.posts,
                run: visit(businessCollections()),
            },
            {
                id: 'section-business-grid',
                group: 'Chapters',
                label: 'Grid',
                hint: 'Plan your Instagram grid before it goes out.',
                keywords: 'instagram grid profile plan preview',
                icon: LayoutGrid,
                chapter: businessChapters.posts,
                run: visit(businessGrids()),
            },
            chapterCommand(businessChapters.inbox, inboxIndex()),
            chapterCommand(businessChapters.ideas, businessIdeas()),
            chapterCommand(businessChapters.media, businessMedia()),
            chapterCommand(businessChapters.insights, businessInsights()),
            chapterCommand(businessChapters.brand, businessBrand()),
            chapterCommand(businessChapters.accounts, businessAccounts()),
            ...(isOwner
                ? [chapterCommand(businessChapters.team, staffIndex())]
                : []),
            chapterCommand(businessChapters.settings, editProfile()),
        ];

        // Phase 15: super admins navigate the platform console, not the studio.
        const list: Command[] =
            auth.user.role === 'super_admin'
                ? [
                      chapterCommand(
                          platformChapters.platformOverview,
                          superAdminDashboard(),
                      ),
                      chapterCommand(
                          platformChapters.organizations,
                          superAdminOrganizations(),
                      ),
                      chapterCommand(
                          platformChapters.analytics,
                          superAdminAnalytics(),
                      ),
                      chapterCommand(
                          platformChapters.reports,
                          superAdminReports(),
                      ),
                      chapterCommand(
                          platformChapters.platformSettings,
                          editProfile(),
                      ),
                  ]
                : isBusiness
                  ? businessCommands
                  : studioCommands;

        for (const action of createActions) {
            list.push({
                id: action.id,
                group: 'Create',
                label: action.label,
                hint: action.hint,
                keywords: action.keywords,
                icon: action.icon,
                run: () => {
                    close();
                    action.perform();
                },
            });
        }

        const toDark = resolvedAppearance !== 'dark';

        list.push({
            id: 'appearance',
            group: 'Appearance',
            label: toDark ? 'Switch to dark mode' : 'Switch to light mode',
            hint: toDark ? 'Cream on Midnight Ink.' : 'Ink on Cloud Cream.',
            keywords: 'theme dark light appearance',
            icon: toDark ? Moon : Sun,
            run: () => {
                updateAppearance(toDark ? 'dark' : 'light');
                close();
            },
        });

        return list;
    }, [
        auth.user.role,
        auth.can?.viewAnalytics,
        isBusiness,
        createActions,
        close,
        resolvedAppearance,
        updateAppearance,
    ]);
}

function CommandPalette({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);
    const listRef = useRef<HTMLDivElement>(null);
    const close = useCallback(() => onOpenChange(false), [onOpenChange]);
    const commands = useCommands(close);

    const results = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (needle === '') {
            return commands;
        }

        // Rank: label prefix > label substring > hint/keywords/number. Stable within a rank, so
        // chapter order is kept.
        const rank = (command: Command): number => {
            const label = command.label.toLowerCase();

            if (label.startsWith(needle)) {
                return 0;
            }

            if (label.includes(needle)) {
                return 1;
            }

            const rest = [
                command.hint,
                command.keywords,
                command.chapter?.number,
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();

            return rest.includes(needle) ? 2 : -1;
        };

        return commands
            .map((command) => ({ command, score: rank(command) }))
            .filter(({ score }) => score >= 0)
            .sort((a, b) => a.score - b.score)
            .map(({ command }) => command);
    }, [commands, query]);

    useEffect(() => {
        if (open) {
            setQuery('');
            setActiveIndex(0);
        }
    }, [open]);

    useEffect(() => {
        setActiveIndex(0);
    }, [query]);

    useEffect(() => {
        listRef.current
            ?.querySelector<HTMLElement>(`[data-index="${activeIndex}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    }, [activeIndex]);

    const onKeyDown = (event: React.KeyboardEvent) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveIndex((index) => Math.min(index + 1, results.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveIndex((index) => Math.max(index - 1, 0));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            results[activeIndex]?.run();
        }
    };

    let lastGroup: Command['group'] | null = null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="top-[18%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-xl [&>button:last-child]:hidden"
                onKeyDown={onKeyDown}
            >
                <DialogTitle className="sr-only">Command palette</DialogTitle>
                <DialogDescription className="sr-only">
                    Search chapters and actions. Use the arrow keys to move and
                    Enter to open.
                </DialogDescription>

                <div className="border-border flex items-center gap-3 border-b px-5">
                    <Search className="text-muted-foreground size-[18px] shrink-0" />
                    <input
                        autoFocus
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Jump to a chapter or action…"
                        aria-label="Search commands"
                        aria-controls="command-palette-results"
                        aria-activedescendant={
                            results[activeIndex]
                                ? `command-${results[activeIndex].id}`
                                : undefined
                        }
                        className="placeholder:text-muted-foreground h-14 w-full bg-transparent text-[15px] outline-none"
                    />
                    <kbd className="eyebrow text-muted-foreground border-border rounded-md border px-1.5 py-0.5">
                        Esc
                    </kbd>
                </div>

                <div
                    ref={listRef}
                    id="command-palette-results"
                    role="listbox"
                    className="max-h-[min(60vh,420px)] overflow-y-auto p-2"
                >
                    {results.length === 0 && (
                        <p className="text-muted-foreground px-3 py-10 text-center text-sm">
                            Nothing matches “{query}”. Try a chapter name.
                        </p>
                    )}
                    {results.map((command, index) => {
                        // Ranked search results can interleave groups, so they get one heading.
                        const isSearching = query.trim() !== '';
                        const heading = isSearching
                            ? 'Best matches'
                            : command.group;
                        const showGroup = isSearching
                            ? index === 0
                            : command.group !== lastGroup;
                        lastGroup = command.group;
                        const isActive = index === activeIndex;

                        return (
                            <div key={command.id}>
                                {showGroup && (
                                    <p className="eyebrow text-muted-foreground px-3 pt-3 pb-1.5">
                                        {heading}
                                    </p>
                                )}
                                <button
                                    type="button"
                                    id={`command-${command.id}`}
                                    role="option"
                                    aria-selected={isActive}
                                    data-index={index}
                                    onMouseMove={() => setActiveIndex(index)}
                                    onClick={command.run}
                                    className={cn(
                                        'flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition-colors',
                                        isActive && 'bg-muted',
                                    )}
                                >
                                    {command.chapter ? (
                                        <span className="flex w-8 shrink-0 items-center gap-1.5">
                                            <span
                                                aria-hidden
                                                className={cn(
                                                    'size-2 rounded-[2px]',
                                                    command.chapter.swatch,
                                                )}
                                            />
                                            <span className="text-muted-foreground font-mono text-[11px] tabular-nums">
                                                {command.chapter.number}
                                            </span>
                                        </span>
                                    ) : (
                                        <span className="bg-accent text-accent-foreground flex size-8 shrink-0 items-center justify-center rounded-full">
                                            <command.icon className="size-4" />
                                        </span>
                                    )}
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <span className="truncate text-sm font-medium">
                                            {command.label}
                                        </span>
                                        {command.hint && (
                                            <span className="text-muted-foreground truncate text-xs">
                                                {command.hint}
                                            </span>
                                        )}
                                    </span>
                                    <CornerDownLeft
                                        aria-hidden
                                        className={cn(
                                            'text-muted-foreground size-4 shrink-0 transition-opacity',
                                            isActive
                                                ? 'opacity-100'
                                                : 'opacity-0',
                                        )}
                                    />
                                </button>
                            </div>
                        );
                    })}
                </div>

                <div className="border-border text-muted-foreground flex items-center justify-between border-t px-5 py-2.5">
                    <span className="eyebrow">Alima Planner</span>
                    <span className="eyebrow flex items-center gap-3">
                        <span>↑↓ Move</span>
                        <span>↵ Open</span>
                    </span>
                </div>
            </DialogContent>
        </Dialog>
    );
}
