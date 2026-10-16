import { Link } from '@inertiajs/react';
import { ArtworkSvg, BrandMark } from '@/components/brand-mark';
import { wordmarkArtwork } from '@/components/brand/artwork';
import { Eyebrow } from '@/components/page';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/**
 * Auth is the brand book's cover (page 001): an Acid Lime panel with an ink running head, the
 * tagline, and the wordmark set huge across the foot ("Ink / Lime", colourway 04). The wordmark is
 * the logo on this panel, so no second lock-up appears beside it.
 */
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
                    <span className="hidden xl:inline">
                        Edition 01 — {new Date().getFullYear()}
                    </span>
                    <span>v1.0</span>
                </div>

                <div className="flex flex-col gap-5">
                    <p className="font-display max-w-xl text-[3.25rem] leading-[1.02] xl:text-[4rem]">
                        Marketing brighter together.
                    </p>
                    <p className="eyebrow">
                        Strategy · Content · Growth · Impact
                    </p>
                </div>

                <ArtworkSvg
                    artwork={wordmarkArtwork}
                    title="Alima"
                    className="-mb-2 h-auto w-full"
                />
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
                            <p className="text-muted-foreground text-[15px] text-pretty">
                                {description}
                            </p>
                        )}
                    </div>
                    <div className="animate-rise [animation-delay:80ms]">
                        {children}
                    </div>
                </div>

                <p className="eyebrow text-muted-foreground text-center lg:text-left">
                    © {new Date().getFullYear()} Alima. Marketing brighter
                    together.
                </p>
            </main>
        </div>
    );
}
