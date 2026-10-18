import { usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { createContext, useContext } from 'react';
import { Peek } from '@/components/brand-poster';
import type { Chapter } from '@/lib/chapters';
import { chapterForPath } from '@/lib/chapters';
import { cn } from '@/lib/utils';

/**
 * Alima page primitives — the app is laid out like the brand book. Every internal page is:
 *
 *   <Page>
 *     <PageHeader eyebrow="Roster" title={<>The <em>roster</em>.</>} actions={…} />
 *     <Panel title="…">…</Panel>
 *   </Page>
 *
 * `PageHeader` opens with the book's running head — an ink rule over `04 — CLIENTS ··· eyebrow` —
 * resolved automatically from the URL via `lib/chapters.ts`. `<em>` inside a title renders as the
 * accent word (Deep Teal; Acid Lime in dark mode), upright — at most one word.
 *
 * `ChapterHero` is the full-bleed chapter opener (Ink block, giant headline, the peek): use it as
 * the one hero on a landing page (Overview, Needs attention) in place of `PageHeader`.
 */

/**
 * True inside a shell that already provides page padding and max-width (the client portal), so
 * `Page` only contributes its vertical rhythm there — and `PageHeader` drops the chapter running
 * head, because the portal is not part of the studio's book.
 */
export const ShellPaddedContext = createContext(false);

export function Page({
    children,
    className,
    width = 'wide',
}: {
    children: ReactNode;
    className?: string;
    width?: 'wide' | 'narrow' | 'full';
}) {
    const shellPadded = useContext(ShellPaddedContext);

    if (shellPadded) {
        return (
            <div className={cn('flex w-full flex-1 flex-col gap-8', className)}>
                {children}
            </div>
        );
    }

    return (
        <div
            className={cn(
                'mx-auto flex w-full flex-1 flex-col gap-8 px-4 pt-6 pb-12 md:px-10 md:pt-10',
                width === 'wide' && 'max-w-[1400px]',
                width === 'narrow' && 'max-w-4xl',
                className,
            )}
        >
            {children}
        </div>
    );
}

/** The chapter the current page belongs to, or null (portal, public pages). */
export function useChapter(): Chapter | null {
    const { url, props } = usePage<{ auth?: { user?: { role?: string } } }>();
    const shellPadded = useContext(ShellPaddedContext);

    return shellPadded
        ? null
        : chapterForPath(url, {
              platform: props.auth?.user?.role === 'super_admin',
          });
}

/** The chapter's swatch square + mono number, e.g. `■ 04 — Clients`. */
export function ChapterMark({
    chapter,
    className,
    showTitle = true,
}: {
    chapter: Chapter;
    className?: string;
    showTitle?: boolean;
}) {
    return (
        <span
            className={cn(
                'eyebrow text-foreground inline-flex items-center gap-2',
                className,
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'size-2.5 shrink-0 rounded-[2px]',
                    chapter.swatch,
                )}
            />
            <span className="tabular-nums">{chapter.number}</span>
            {showTitle && (
                <>
                    <span aria-hidden className="opacity-40">
                        —
                    </span>
                    <span>{chapter.title}</span>
                </>
            )}
        </span>
    );
}

/**
 * The book's page head (every spread in the guidelines opens with one): a mono line over a full
 * ink hairline. Left carries the chapter; right carries the page's own eyebrow.
 */
export function RunningHead({
    left,
    right,
    className,
    inverse = false,
}: {
    left?: ReactNode;
    right?: ReactNode;
    className?: string;
    inverse?: boolean;
}) {
    return (
        <div
            className={cn(
                'eyebrow flex flex-wrap items-center justify-between gap-x-6 gap-y-1 border-b pb-2.5',
                inverse
                    ? 'border-cream/25 text-cream/70'
                    : 'border-foreground/85 text-muted-foreground',
                className,
            )}
        >
            <span className="flex min-w-0 items-center gap-2.5">{left}</span>
            {right && (
                <span className="min-w-0 truncate text-right">{right}</span>
            )}
        </div>
    );
}

export function PageHeader({
    eyebrow,
    title,
    description,
    actions,
    meta,
    className,
}: {
    eyebrow?: ReactNode;
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    meta?: ReactNode;
    className?: string;
}) {
    const chapter = useChapter();

    return (
        <header className={cn('animate-rise flex flex-col gap-7', className)}>
            {chapter ? (
                <RunningHead
                    left={<ChapterMark chapter={chapter} />}
                    right={eyebrow}
                />
            ) : (
                eyebrow && <Eyebrow dash>{eyebrow}</Eyebrow>
            )}
            <div className="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
                <div className="flex min-w-0 flex-col gap-3.5">
                    <h1 className="font-display [&_em]:text-primary text-[2.4rem] leading-[1.02] text-balance sm:text-[3.25rem] [&_em]:not-italic">
                        {title}
                    </h1>
                    {description && (
                        <p className="text-muted-foreground max-w-2xl text-[15px] leading-relaxed text-pretty">
                            {description}
                        </p>
                    )}
                    {meta && (
                        <div className="flex flex-wrap items-center gap-2 pt-1">
                            {meta}
                        </div>
                    )}
                </div>
                {actions && (
                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                        {actions}
                    </div>
                )}
            </div>
        </header>
    );
}

/**
 * The chapter opener (book chapter spreads): an Ink block with the running head, a giant
 * headline with one Lime accent word, the single orange dash, and the peek bleeding off the bottom
 * edge. `aside` sits top-right (a hero figure works best). One per page; it carries the page's
 * one graphic, so nothing else on the page gets a pattern or peek.
 */
export function ChapterHero({
    eyebrow,
    title,
    description,
    actions,
    aside,
    peek = true,
    className,
}: {
    eyebrow?: ReactNode;
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    aside?: ReactNode;
    peek?: boolean;
    className?: string;
}) {
    const chapter = useChapter();

    return (
        <header
            className={cn(
                'animate-rise bg-ink text-ink-foreground dark:ring-cream/10 relative isolate overflow-hidden rounded-[28px] dark:ring-1',
                className,
            )}
        >
            {peek && (
                <Peek className="text-lilac right-[-3%] -z-10 hidden w-[min(28%,340px)] md:block" />
            )}
            <div className="flex flex-col gap-10 p-6 sm:p-10 lg:p-12">
                <RunningHead
                    inverse
                    left={
                        chapter && (
                            <ChapterMark
                                chapter={chapter}
                                className="text-cream"
                            />
                        )
                    }
                    right={eyebrow}
                />
                <div className="grid gap-10 lg:grid-cols-[minmax(0,1fr)_auto]">
                    <div className="flex max-w-3xl flex-col gap-6">
                        <h1 className="font-display [&_em]:text-lime text-[2.75rem] leading-[0.98] text-balance sm:text-[4rem] xl:text-[4.75rem] [&_em]:not-italic">
                            {title}
                        </h1>
                        <span
                            aria-hidden
                            className="bg-signal h-1 w-12 rounded-full"
                        />
                        {description && (
                            <p className="text-cream/72 max-w-xl text-base leading-relaxed text-pretty">
                                {description}
                            </p>
                        )}
                        {actions && (
                            <div className="flex flex-wrap items-center gap-2.5 pt-1">
                                {actions}
                            </div>
                        )}
                    </div>
                    {aside && (
                        <div className="flex flex-col items-start lg:items-end lg:pb-40">
                            {aside}
                        </div>
                    )}
                </div>
            </div>
        </header>
    );
}

/**
 * DM Mono spec-sheet label. `dash` prefixes the rounded Signal Orange rule — Alima allows one per
 * layout (§4.4), so only hero headers and pages without a chapter running head use it.
 */
export function Eyebrow({
    children,
    dash = false,
    className,
}: {
    children: ReactNode;
    dash?: boolean;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'eyebrow text-muted-foreground flex items-center gap-2.5',
                className,
            )}
        >
            {dash && (
                <span
                    aria-hidden
                    className="bg-signal h-[3px] w-5 shrink-0 rounded-full"
                />
            )}
            {children}
        </span>
    );
}

/** A surface panel (24px radius, shadow.sm) with an optional header row. */
export function Panel({
    title,
    eyebrow,
    description,
    icon: Icon,
    actions,
    children,
    className,
    bodyClassName,
    id,
}: {
    /** Anchor target for deep links (e.g. `#results`). */
    id?: string;
    title?: ReactNode;
    eyebrow?: ReactNode;
    description?: ReactNode;
    icon?: LucideIcon;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
    bodyClassName?: string;
}) {
    const hasHeader = title || eyebrow || actions;

    return (
        <section
            id={id}
            className={cn(
                'border-border/80 bg-card text-card-foreground flex scroll-mt-24 flex-col rounded-2xl border shadow-sm',
                className,
            )}
        >
            {hasHeader && (
                <div className="border-border/70 mx-5 flex items-start justify-between gap-4 border-b pt-5 pb-4 sm:mx-6">
                    <div className="flex min-w-0 items-start gap-3">
                        {Icon && (
                            <span className="bg-accent text-accent-foreground flex size-9 shrink-0 items-center justify-center rounded-full">
                                <Icon className="size-[18px]" />
                            </span>
                        )}
                        <div className="flex min-w-0 flex-col gap-1">
                            {eyebrow && (
                                <span className="eyebrow text-muted-foreground">
                                    {eyebrow}
                                </span>
                            )}
                            {title && (
                                <h2 className="text-[17px] leading-snug font-semibold tracking-[-0.01em]">
                                    {title}
                                </h2>
                            )}
                            {description && (
                                <p className="text-muted-foreground text-[13px]">
                                    {description}
                                </p>
                            )}
                        </div>
                    </div>
                    {actions && (
                        <div className="flex shrink-0 items-center gap-2">
                            {actions}
                        </div>
                    )}
                </div>
            )}
            <div className={cn('flex-1 p-5 sm:p-6', bodyClassName)}>
                {children}
            </div>
        </section>
    );
}

export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    className,
    compact = false,
}: {
    icon?: LucideIcon;
    title: ReactNode;
    description?: ReactNode;
    action?: ReactNode;
    className?: string;
    compact?: boolean;
}) {
    return (
        <div
            className={cn(
                'border-border flex flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed text-center',
                compact ? 'px-4 py-8' : 'bg-card/50 min-h-[38vh] px-6 py-14',
                className,
            )}
        >
            {Icon && (
                <span
                    className={cn(
                        'bg-lilac text-ink flex items-center justify-center rounded-full',
                        compact ? 'size-11' : 'size-14',
                    )}
                >
                    <Icon className={compact ? 'size-5' : 'size-6'} />
                </span>
            )}
            <p
                className={cn(
                    'font-display leading-tight',
                    compact ? 'text-lg' : 'text-[1.75rem]',
                )}
            >
                {title}
            </p>
            {description && (
                <p className="text-muted-foreground max-w-sm text-[13px] text-pretty">
                    {description}
                </p>
            )}
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}

/**
 * A big-number stat for KPI rows (Poppins Bold — "big numbers", §4.2). `tone="ink"` renders the
 * inverse Ink block for the one hero stat per row; `tone="accent"` is a Deep Teal block.
 */
export function Stat({
    label,
    value,
    hint,
    icon: Icon,
    tone = 'paper',
    className,
}: {
    label: ReactNode;
    value: ReactNode;
    hint?: ReactNode;
    icon?: LucideIcon;
    tone?: 'paper' | 'ink' | 'accent';
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col justify-between gap-7 rounded-2xl border p-5',
                tone === 'paper' && 'border-border/80 bg-card shadow-sm',
                tone === 'ink' &&
                    'bg-ink text-ink-foreground dark:border-cream/10 border-transparent',
                tone === 'accent' && 'bg-teal text-cream border-transparent',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-2">
                <span
                    className={cn(
                        'eyebrow',
                        tone === 'paper' && 'text-muted-foreground',
                        tone !== 'paper' && 'opacity-70',
                    )}
                >
                    {label}
                </span>
                {Icon && (
                    <Icon
                        className={cn(
                            'size-4',
                            tone === 'paper' ? 'opacity-50' : 'text-lime',
                        )}
                    />
                )}
            </div>
            <div className="flex flex-col gap-1.5">
                <span className="font-display text-[2.85rem] leading-none font-bold tracking-[-0.035em] tabular-nums">
                    {value}
                </span>
                {hint && (
                    <span
                        className={cn(
                            'text-xs',
                            tone === 'paper'
                                ? 'text-muted-foreground'
                                : 'opacity-70',
                        )}
                    >
                        {hint}
                    </span>
                )}
            </div>
        </div>
    );
}

export type StatStripItem = {
    label: ReactNode;
    value: ReactNode;
    caption?: ReactNode;
    /** Makes the whole cell a link target (render a `<Link>` via `render`). */
    render?: (cell: ReactNode) => ReactNode;
};

/**
 * The book's spec row (§2.5 "Minimum sizes"): figures side by side between vertical hairlines,
 * each a mono label, a huge Poppins Bold numeral, and a mono caption. Calmer than a row of cards;
 * use it for a page's secondary KPIs.
 */
export function StatStrip({
    items,
    className,
}: {
    items: StatStripItem[];
    className?: string;
}) {
    return (
        <dl
            className={cn(
                'border-foreground/85 grid grid-cols-2 border-t lg:auto-cols-fr lg:grid-flow-col lg:grid-cols-none',
                className,
            )}
        >
            {items.map((item, index) => {
                const cell = (
                    <div className="flex h-full flex-col gap-4 py-5 pr-4">
                        <dt className="eyebrow text-muted-foreground">
                            {item.label}
                        </dt>
                        <dd className="flex flex-col gap-2">
                            <span className="font-display text-[3.25rem] leading-[0.9] font-bold tracking-[-0.04em] tabular-nums sm:text-[4rem]">
                                {item.value}
                            </span>
                            {item.caption && (
                                <span className="eyebrow text-muted-foreground tracking-[0.04em] normal-case">
                                    {item.caption}
                                </span>
                            )}
                        </dd>
                    </div>
                );

                return (
                    <div
                        key={index}
                        className={cn(
                            'border-border min-w-0 pl-4 first:pl-0',
                            index > 0 && 'lg:border-l',
                            index % 2 === 1 && 'border-l lg:border-l',
                            index >= 2 && 'border-t lg:border-t-0',
                            index % 2 === 0 && 'max-lg:pl-0',
                        )}
                    >
                        {item.render ? item.render(cell) : cell}
                    </div>
                );
            })}
        </dl>
    );
}
