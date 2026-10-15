import { Link } from '@inertiajs/react';
import { BrandMark } from '@/components/brand-mark';
import { BrandPoster } from '@/components/brand-poster';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="bg-background grid min-h-svh grid-cols-1 lg:grid-cols-[1.05fr_1fr]">
            <aside className="bg-ink text-ink-foreground relative hidden overflow-hidden p-10 lg:flex lg:flex-col lg:justify-between">
                <div
                    aria-hidden
                    className="bg-grid text-ink-foreground pointer-events-none absolute inset-0 [mask-image:linear-gradient(to_bottom,black,transparent_85%)]"
                />
                <div className="relative flex items-center justify-between">
                    <Link href={home()} aria-label="Graphy home">
                        <BrandMark size="md" wordmark />
                    </Link>
                    <span className="eyebrow text-ink-foreground/50">
                        Studio OS
                    </span>
                </div>

                <BrandPoster className="relative mx-auto w-full max-w-[19rem] xl:max-w-sm" />

                <div className="relative flex flex-col gap-6">
                    <p className="font-serif text-5xl leading-[0.95] tracking-[-0.02em] xl:text-6xl">
                        Plan it. <em className="text-primary">Approve</em> it.
                        <br />
                        Ship it.
                    </p>
                    <div className="eyebrow border-ink-foreground/15 text-ink-foreground/50 flex items-center justify-between border-t pt-4">
                        <span>Clients · Campaigns · Calendar</span>
                        <span>© {new Date().getFullYear()}</span>
                    </div>
                </div>
            </aside>

            <main className="flex flex-col px-6 py-8 sm:px-10">
                <div className="flex items-center justify-between lg:hidden">
                    <Link href={home()} aria-label="Graphy home">
                        <BrandMark size="sm" wordmark />
                    </Link>
                </div>

                <div className="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center gap-8 py-10">
                    <div className="animate-rise flex flex-col gap-3">
                        <span className="eyebrow text-muted-foreground flex items-center gap-2">
                            <span
                                aria-hidden
                                className="bg-primary size-1.5 rounded-[2px]"
                            />
                            Graphy
                        </span>
                        <h1 className="font-serif text-[2.6rem] leading-[0.95] tracking-[-0.02em] text-balance">
                            {title}
                        </h1>
                        {description && (
                            <p className="text-muted-foreground text-[15px]">
                                {description}
                            </p>
                        )}
                    </div>
                    <div className="animate-rise [animation-delay:80ms]">
                        {children}
                    </div>
                </div>
            </main>
        </div>
    );
}
