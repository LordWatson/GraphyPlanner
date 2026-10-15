import type { ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { cn } from '@/lib/utils';

/**
 * Shell for token-link pages opened outside the app (client review, invites) — guidelines §7:
 * no navigation, generous whitespace, only the client's own context, mobile-first.
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
            <div
                aria-hidden
                className="bg-grid text-foreground pointer-events-none absolute inset-x-0 top-0 h-80 [mask-image:linear-gradient(to_bottom,black,transparent)]"
            />
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
                <span>© {new Date().getFullYear()} Graphy</span>
                <span>Secure link</span>
            </footer>
        </div>
    );
}

/** The large serif title block used at the top of every public page. */
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
            <span className="eyebrow text-muted-foreground flex items-center gap-2">
                <span
                    aria-hidden
                    className="bg-primary size-1.5 rounded-[2px]"
                />
                {kicker}
            </span>
            <h1 className="[&_em]:text-primary font-serif text-[2.6rem] leading-[0.95] tracking-[-0.02em] text-balance sm:text-5xl [&_em]:italic">
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
