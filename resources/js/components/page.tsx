import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { createContext, useContext } from 'react';
import { cn } from '@/lib/utils';

/**
 * Alima page primitives. Every internal page is:
 *
 *   <Page>
 *     <PageHeader eyebrow="Clients" title={<>The <em>roster</em>.</>} actions={…} />
 *     <Panel title="…">…</Panel>
 *   </Page>
 *
 * `<em>` inside a title renders as the brand accent word (Deep Teal; Acid Lime in dark mode),
 * upright — use it for at most one word. The eyebrow carries the layout's one rounded orange dash.
 */
/**
 * True inside a shell that already provides page padding and max-width (the client portal), so
 * `Page` only contributes its vertical rhythm there.
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
                'mx-auto flex w-full flex-1 flex-col gap-8 px-4 py-6 md:px-8 md:py-9',
                width === 'wide' && 'max-w-[1360px]',
                width === 'narrow' && 'max-w-4xl',
                className,
            )}
        >
            {children}
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
    return (
        <header
            className={cn(
                'animate-rise border-border flex flex-col gap-5 border-b pb-7 md:flex-row md:items-end md:justify-between',
                className,
            )}
        >
            <div className="flex min-w-0 flex-col gap-3">
                {eyebrow && <Eyebrow dash>{eyebrow}</Eyebrow>}
                <h1 className="font-display [&_em]:text-primary text-[2.25rem] leading-[1.05] text-balance sm:text-[2.75rem] [&_em]:not-italic">
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
        </header>
    );
}

/**
 * DM Mono spec-sheet label. `dash` prefixes the rounded Signal Orange rule — Alima allows one per
 * layout (§4.4), so only page/hero headers use it.
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

/** A soft surface panel (cards 24px, shadow.sm) with an optional header row. */
export function Panel({
    title,
    eyebrow,
    description,
    icon: Icon,
    actions,
    children,
    className,
    bodyClassName,
}: {
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
            className={cn(
                'border-border/70 bg-card text-card-foreground flex flex-col rounded-2xl border shadow-sm',
                className,
            )}
        >
            {hasHeader && (
                <div className="border-border/70 flex items-start justify-between gap-4 border-b px-5 py-4">
                    <div className="flex min-w-0 items-start gap-3">
                        {Icon && (
                            <span className="bg-accent text-accent-foreground flex size-8 shrink-0 items-center justify-center rounded-full">
                                <Icon className="size-4" />
                            </span>
                        )}
                        <div className="flex min-w-0 flex-col gap-0.5">
                            {eyebrow && (
                                <span className="eyebrow text-muted-foreground">
                                    {eyebrow}
                                </span>
                            )}
                            {title && (
                                <h2 className="text-[15px] leading-snug font-semibold tracking-[-0.005em]">
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
            <div className={cn('flex-1 p-5', bodyClassName)}>{children}</div>
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
                <span className="bg-lilac/45 text-ink dark:bg-lilac/25 dark:text-lilac flex size-12 items-center justify-center rounded-full">
                    <Icon className="size-5" />
                </span>
            )}
            <p
                className={cn(
                    'font-display leading-tight',
                    compact ? 'text-lg' : 'text-2xl',
                )}
            >
                {title}
            </p>
            {description && (
                <p className="text-muted-foreground max-w-sm text-[13px]">
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
                'flex flex-col justify-between gap-6 rounded-2xl border p-5',
                tone === 'paper' && 'border-border/70 bg-card shadow-sm',
                tone === 'ink' &&
                    'bg-ink text-ink-foreground border-transparent',
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
            <div className="flex flex-col gap-1">
                <span className="font-display text-[2.6rem] leading-none font-bold tracking-[-0.03em] tabular-nums">
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
