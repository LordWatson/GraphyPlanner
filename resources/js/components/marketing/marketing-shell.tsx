import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, ArrowUpRight } from 'lucide-react';
import type { PropsWithChildren, ReactNode } from 'react';

import { BrandMark } from '@/components/brand-mark';
import { Button } from '@/components/ui/button';
import {
    about,
    dashboard,
    forBusiness,
    forCreators,
    login,
    pricing,
    register,
} from '@/routes';
import type { Auth } from '@/types';

/**
 * Step CG-2.7 — the standalone chrome shared by the public segment & pricing pages. Mirrors the
 * welcome page's header/footer so the marketing surface reads as one brand.
 */
export function MarketingShell({
    title,
    children,
}: PropsWithChildren<{ title: string }>) {
    const { auth } = usePage<{ auth: Auth }>().props;

    return (
        <>
            <Head title={title} />
            <div className="bg-background text-foreground relative flex min-h-screen flex-col">
                <header className="relative mx-auto flex h-[88px] w-full max-w-[1280px] items-center justify-between px-5 md:px-10 lg:px-20">
                    <Link href="/" aria-label="Alima home">
                        <BrandMark size="md" wordmark />
                    </Link>
                    <nav className="flex items-center gap-1.5">
                        <Button
                            variant="ghost"
                            asChild
                            className="hidden sm:inline-flex"
                        >
                            <Link href={forBusiness()}>For business</Link>
                        </Button>
                        <Button
                            variant="ghost"
                            asChild
                            className="hidden sm:inline-flex"
                        >
                            <Link href={forCreators()}>For creators</Link>
                        </Button>
                        <Button
                            variant="ghost"
                            asChild
                            className="hidden sm:inline-flex"
                        >
                            <Link href={pricing()}>Pricing</Link>
                        </Button>
                        {auth.user ? (
                            <Button asChild>
                                <Link href={dashboard()}>
                                    Open planner
                                    <ArrowRight />
                                </Link>
                            </Button>
                        ) : (
                            <>
                                <Button variant="ghost" asChild>
                                    <Link href={login()}>Log in</Link>
                                </Button>
                                <Button asChild>
                                    <Link href={register()}>Start free</Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </header>

                <main className="relative mx-auto flex w-full max-w-[1280px] flex-1 flex-col gap-24 px-5 pb-24 md:px-10 lg:px-20">
                    {children}
                </main>

                <footer className="bg-ink text-cream relative">
                    <div className="mx-auto flex w-full max-w-[1280px] flex-col gap-12 px-5 pt-16 pb-8 md:px-10 lg:px-20">
                        <div className="flex flex-wrap items-end justify-between gap-8">
                            <div className="flex flex-col gap-6">
                                <BrandMark
                                    size="lg"
                                    wordmark
                                    className="text-cream"
                                />
                                <p className="font-display max-w-md text-3xl leading-[1.1]">
                                    Marketing brighter together
                                    <span className="text-signal">.</span>
                                </p>
                            </div>
                            <Link
                                href={about()}
                                className="group text-cream/80 hover:text-cream inline-flex items-center gap-1.5 text-sm font-medium"
                            >
                                More about Alima
                                <ArrowUpRight className="size-4 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
                            </Link>
                        </div>
                        <div className="eyebrow border-cream/15 text-cream/55 flex flex-wrap justify-between gap-3 border-t pt-4">
                            <span>© {new Date().getFullYear()} Alima</span>
                            <span>Strategy · Content · Growth · Impact</span>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}

/**
 * A simple on-brand hero used across the marketing pages.
 */
export function MarketingHero({
    eyebrow,
    title,
    description,
    children,
}: {
    eyebrow: string;
    title: ReactNode;
    description: string;
    children?: ReactNode;
}) {
    return (
        <section className="animate-rise border-foreground relative flex flex-col justify-center gap-8 border-b-2 pt-10 pb-16">
            <span className="eyebrow text-primary">{eyebrow}</span>
            <h1 className="font-display max-w-4xl text-[clamp(2.75rem,7vw,5rem)] leading-[1.03]">
                {title}
            </h1>
            <p className="text-muted-foreground max-w-xl text-lg leading-relaxed text-pretty">
                {description}
            </p>
            {children}
        </section>
    );
}
