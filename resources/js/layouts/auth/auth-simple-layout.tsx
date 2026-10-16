import { Link } from '@inertiajs/react';
import { BrandMark } from '@/components/brand-mark';
import { Peek } from '@/components/brand-poster';
import { Eyebrow } from '@/components/page';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="bg-background grid min-h-svh grid-cols-1 lg:grid-cols-[1.05fr_1fr]">
            <aside className="bg-ink text-cream relative hidden overflow-hidden p-10 lg:flex lg:flex-col lg:gap-14">
                <div className="relative flex items-center justify-between">
                    <Link href={home()} aria-label="Alima home">
                        <BrandMark size="md" wordmark className="text-cream" />
                    </Link>
                    <span className="eyebrow text-cream/60">Planner</span>
                </div>

                <div className="relative flex flex-col gap-5">
                    <span className="eyebrow text-lime">
                        Strategy · Content · Growth · Impact
                    </span>
                    <p className="font-display text-[3.5rem] leading-[1.02] xl:text-[4.25rem]">
                        Marketing brighter together
                        <span className="text-signal">.</span>
                    </p>
                </div>

                <Peek className="text-lilac left-[12%] w-[76%]" />
            </aside>

            <main className="flex flex-col px-6 py-8 sm:px-10">
                <div className="flex items-center justify-between lg:hidden">
                    <Link href={home()} aria-label="Alima home">
                        <BrandMark size="sm" wordmark />
                    </Link>
                </div>

                <div className="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center gap-8 py-10">
                    <div className="animate-rise flex flex-col gap-3">
                        <Eyebrow dash>Alima Planner</Eyebrow>
                        <h1 className="font-display text-[2.25rem] leading-[1.08] text-balance">
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
