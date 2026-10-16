import type { ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { Eyebrow } from '@/components/page';
import { cn } from '@/lib/utils';

/**
 * Shell for token-link pages opened outside the app (client review, invites): no navigation,
 * generous whitespace, only the client's own context, mobile-first. A thin Alima spectrum band
 * (§3.8 — hero bands) crowns the page; the logo sits on calm cream below it.
 */
export function PublicShell({
    children,
    context,
    width = 'md',
}: {
    children: ReactNode;
    context?: ReactNode;
    width?: 'md' | 'lg';
}) {
    const max = width === 'lg' ? 'max-w-2xl' : 'max-w-md';

    return (
        <div className="bg-background text-foreground relative flex min-h-screen flex-col">
            <div aria-hidden className="bg-spectrum h-1.5 w-full" />
            <header
                className={cn(
                    'relative mx-auto flex w-full items-center justify-between gap-4 px-6 pt-8 pb-10',
                    max,
                )}
            >
                <BrandMark size="sm" wordmark />
                {context && (
                    <span className="eyebrow border-border bg-card text-muted-foreground truncate rounded-full border px-3 py-1">
                        {context}
                    </span>
                )}
            </header>

            <main
                className={cn(
                    'animate-rise relative mx-auto flex w-full flex-1 flex-col gap-6 px-6 pb-16',
                    max,
                )}
            >
                {children}
            </main>

            <footer
                className={cn(
                    'eyebrow text-muted-foreground mx-auto flex w-full justify-between px-6 py-6',
                    max,
                )}
            >
                <span>© {new Date().getFullYear()} Alima</span>
                <span>Marketing brighter together.</span>
            </footer>
        </div>
    );
}

/** The large display title block used at the top of every public page. */
export function PublicTitle({
    kicker,
    title,
    children,
}: {
    kicker: ReactNode;
    title: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-3">
            <Eyebrow dash>{kicker}</Eyebrow>
            <h1 className="font-display [&_em]:text-primary text-[2.4rem] leading-[1.05] text-balance sm:text-5xl [&_em]:not-italic">
                {title}
            </h1>
            {children && (
                <div className="text-muted-foreground text-[15px]">
                    {children}
                </div>
            )}
        </div>
    );
}
