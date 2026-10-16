import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    BarChart3,
    CalendarClock,
    FileCheck2,
    LayoutGrid,
    PlugZap,
    Receipt,
    ShieldCheck,
    Sparkles,
} from 'lucide-react';
import type { ReactNode } from 'react';

import { BrandMark } from '@/components/brand-mark';
import { BrandPattern } from '@/components/brand-poster';
import { Eyebrow } from '@/components/page';
import { Button } from '@/components/ui/button';
import { about, dashboard, home, login, register } from '@/routes';

const pillars = [
    {
        icon: LayoutGrid,
        title: 'Clients, brands & campaigns in one place',
        description:
            'Keep every client, their brand voice, and the campaigns you run for them side by side — no scattered spreadsheets, no lost context.',
    },
    {
        icon: CalendarClock,
        title: 'A shared content calendar',
        description:
            'Draft, schedule and see every post across every client on one calendar the whole team can plan around.',
    },
    {
        icon: FileCheck2,
        title: 'Transparent approvals',
        description:
            'Every post moves through a clear status — draft, internal review, waiting on client, approved — so nobody has to ask "where are we on this?"',
    },
    {
        icon: ShieldCheck,
        title: 'A dedicated client portal',
        description:
            'Clients approve or request changes from a clean, branded portal. No confusion about what they are looking at.',
    },
    {
        icon: Sparkles,
        title: 'AI-assisted content',
        description:
            'On-brand post ideas, caption suggestions and trend insights from each client’s BrandBrain — never start from a blank page.',
    },
    {
        icon: PlugZap,
        title: 'Publish from one place',
        description:
            'Approved posts go straight to Instagram, TikTok and more via Upload-Post, with status synced back automatically.',
    },
    {
        icon: Receipt,
        title: 'Simple client billing',
        description:
            'Generate, send and track invoices per client in the same tool you use to run their content.',
    },
    {
        icon: BarChart3,
        title: 'Client health at a glance',
        description:
            'A Red / Amber / Green signal keeps account owners ahead of at-risk clients before they become a problem.',
    },
];

const workflow = [
    {
        step: '01',
        title: 'Bring your clients in',
        description:
            'Add clients, define their brand voice, and connect their social accounts.',
    },
    {
        step: '02',
        title: 'Plan the work',
        description:
            'Build campaigns, draft posts, and let AI suggest ideas and captions that fit the brand.',
    },
    {
        step: '03',
        title: 'Review & approve',
        description:
            'The team reviews internally, then clients approve or request changes from their own portal.',
    },
    {
        step: '04',
        title: 'Publish & track',
        description:
            'Approved posts go live on schedule, and every status tracks back to one source of truth.',
    },
];

function SectionTitle({
    eyebrow,
    title,
}: {
    eyebrow: string;
    title: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-3">
            <Eyebrow className="text-primary">{eyebrow}</Eyebrow>
            <h2 className="font-display [&_em]:text-primary max-w-3xl text-4xl leading-[1.08] lg:text-[2.75rem] [&_em]:not-italic">
                {title}
            </h2>
        </div>
    );
}

export default function About() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="About" />
            <div className="bg-background text-foreground relative flex min-h-screen flex-col overflow-hidden">
                <div
                    aria-hidden
                    className="bg-grid text-foreground pointer-events-none absolute inset-x-0 top-0 h-[560px] [mask-image:radial-gradient(ellipse_70%_60%_at_50%_0%,black,transparent)]"
                />

                <header className="relative mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-6">
                    <Link href={home()} aria-label="Graphy home">
                        <BrandMark size="sm" wordmark />
                    </Link>
                    <nav className="flex items-center gap-1.5">
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
                                    <Link href={register()}>
                                        Let&apos;s talk
                                    </Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </header>

                <main className="relative mx-auto flex w-full max-w-[1280px] flex-1 flex-col gap-28 px-5 pt-12 pb-24 md:px-10 lg:px-20 lg:pt-20">
                    <section className="animate-rise flex flex-col gap-8">
                        <span className="eyebrow text-muted-foreground flex items-center gap-2">
                            <span
                                aria-hidden
                                className="bg-primary size-1.5 rounded-[2px]"
                            />
                            About Graphy
                        </span>
                        <h1 className="max-w-4xl font-serif text-[clamp(3rem,7.5vw,6.25rem)] leading-[0.9] tracking-[-0.03em]">
                            The <em className="text-primary">studio OS</em> for
                            running social content, end to end.
                        </h1>
                        <p className="text-muted-foreground max-w-2xl text-lg leading-relaxed text-pretty">
                            Alima Planner brings clients, brands, campaigns,
                            approvals, AI assistance and publishing together in
                            one workspace — built for a studio that runs dozens
                            of clients at once.
                        </p>
                    </section>

                    <section className="flex flex-col gap-10">
                        <SectionTitle
                            eyebrow="Everything you need"
                            title={
                                <>
                                    One workspace, <em>every part</em> of the
                                    job.
                                </>
                            }
                        />
                        <div className="border-border/70 bg-border/70 grid w-full grid-cols-1 gap-px overflow-hidden rounded-2xl border shadow-sm sm:grid-cols-2 lg:grid-cols-4">
                            {pillars.map(
                                ({ icon: Icon, title, description }) => (
                                    <div
                                        key={title}
                                        className="bg-card flex flex-col items-start gap-3 p-6"
                                    >
                                        <span className="bg-accent text-accent-foreground flex size-10 items-center justify-center rounded-full">
                                            <Icon className="size-5" />
                                        </span>
                                        <h3 className="text-[15px] font-semibold">
                                            {title}
                                        </h3>
                                        <p className="text-muted-foreground text-[13px] leading-relaxed">
                                            {description}
                                        </p>
                                    </div>
                                ),
                            )}
                        </div>
                    </section>

                    <section className="flex flex-col gap-10">
                        <SectionTitle
                            eyebrow="How it works"
                            title={
                                <>
                                    From brief to published, <em>together</em>.
                                </>
                            }
                        />
                        <ol className="grid w-full grid-cols-1 gap-10 sm:grid-cols-2 lg:grid-cols-4">
                            {workflow.map(({ step, title, description }) => (
                                <li
                                    key={step}
                                    className="border-foreground flex flex-col gap-3 border-t-2 pt-5"
                                >
                                    <span className="text-primary font-mono text-xs font-medium">
                                        {step}
                                    </span>
                                    <h3 className="font-display text-2xl leading-[1.15]">
                                        {title}
                                    </h3>
                                    <p className="text-muted-foreground text-sm leading-relaxed">
                                        {description}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </section>

                    <section className="grid grid-cols-1 overflow-hidden rounded-2xl lg:grid-cols-[1.4fr_1fr]">
                        <div className="bg-ink text-cream flex flex-col justify-between gap-10 p-10 lg:p-14">
                            <div className="flex max-w-xl flex-col gap-4">
                                <h2 className="font-display text-4xl leading-[1.08] lg:text-5xl">
                                    Stop chasing approvals in Slack threads
                                    <span className="text-signal">.</span>
                                </h2>
                                <p className="text-cream/70 max-w-md text-[15px]">
                                    Bring clients, content and approvals into
                                    one bright home.
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-3">
                                {!auth.user ? (
                                    <>
                                        <Button
                                            size="lg"
                                            variant="spark"
                                            asChild
                                        >
                                            <Link href={register()}>
                                                Start a project
                                                <ArrowRight />
                                            </Link>
                                        </Button>
                                        <Button
                                            size="lg"
                                            variant="outline"
                                            className="border-cream/60 text-cream hover:bg-cream/10 hover:text-cream"
                                            asChild
                                        >
                                            <Link href={login()}>Log in</Link>
                                        </Button>
                                    </>
                                ) : (
                                    <Button size="lg" variant="spark" asChild>
                                        <Link href={dashboard()}>
                                            Open planner
                                            <ArrowRight />
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        </div>
                        <BrandPattern
                            variant="confetti"
                            pair="teal-lilac"
                            className="min-h-56"
                        />
                    </section>
                </main>

                <footer className="eyebrow text-muted-foreground mx-auto flex w-full max-w-6xl justify-between px-6 py-6">
                    <span>© {new Date().getFullYear()} Graphy</span>
                    <Link href={about()} className="hover:text-foreground">
                        About
                    </Link>
                </footer>
            </div>
        </>
    );
}
