import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { createContext, useContext } from 'react';
import { cn } from '@/lib/utils';

/**
 * Studio Paper page primitives. Every internal page is:
 *
 *   <Page>
 *     <PageHeader eyebrow="Clients" title={<>The <em>roster</em></>} actions={…} />
 *     <Panel title="…">…</Panel>
 *   </Page>
 *
 * `<em>` inside a title renders as the italic orange accent word — use it for at most one word.
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
                {eyebrow && (
                    <span className="eyebrow text-muted-foreground flex items-center gap-2">
                        <span
                            aria-hidden
                            className="bg-primary size-1.5 rounded-[2px]"
                        />
                        {eyebrow}
                    </span>
                )}
                <h1 className="[&_em]:text-primary font-serif text-[2.6rem] leading-[0.95] tracking-[-0.02em] text-balance sm:text-[3.25rem] [&_em]:italic">
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

/** A bordered paper panel with an optional editorial header row. */
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
                'border-border bg-card text-card-foreground flex flex-col rounded-2xl border',
                className,
            )}
        >
            {hasHeader && (
                <div className="border-border/70 flex items-start justify-between gap-4 border-b px-5 py-4">
                    <div className="flex min-w-0 items-start gap-3">
                        {Icon && (
                            <span className="border-border bg-background text-foreground/70 mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg border">
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
                'border-border flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed text-center',
                compact ? 'px-4 py-8' : 'bg-card/40 min-h-[38vh] px-6 py-14',
                className,
            )}
        >
            {Icon && (
                <span className="relative flex size-12 items-center justify-center">
                    <span
                        aria-hidden
                        className="bg-accent absolute inset-0 rotate-6 rounded-xl"
                    />
                    <span className="border-border bg-card text-primary relative flex size-12 items-center justify-center rounded-xl border">
                        <Icon className="size-5" />
                    </span>
                </span>
            )}
            <p
                className={cn(
                    'font-serif leading-tight',
                    compact ? 'text-xl' : 'text-[1.75rem]',
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

/** A big-number stat for KPI rows. `tone="ink"` renders the inverse ink block for the hero stat. */
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
                tone === 'paper' && 'border-border bg-card',
                tone === 'ink' &&
                    'bg-ink text-ink-foreground border-transparent',
                tone === 'accent' &&
                    'border-primary/15 bg-accent text-accent-foreground',
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
                            tone === 'ink' ? 'text-primary' : 'opacity-50',
                        )}
                    />
                )}
            </div>
            <div className="flex flex-col gap-1">
                <span className="font-serif text-5xl leading-none tracking-[-0.02em] tabular-nums">
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
