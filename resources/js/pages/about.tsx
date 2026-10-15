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
import { BrandPoster } from '@/components/brand-poster';
import { Button } from '@/components/ui/button';
import { about, dashboard, home, login, register } from '@/routes';

const pillars = [
    {
        icon: LayoutGrid,
        title: 'Clients, brands & campaigns in one place',
        description:
            'Keep every client, their brand voice, and the campaigns you run for them organized side by side — no more scattered spreadsheets or lost context.',
    },
    {
        icon: CalendarClock,
        title: 'A shared content calendar',
        description:
            'Draft, schedule, and visualize every post across every client on one dependable calendar your whole team can trust and plan around.',
    },
    {
        icon: FileCheck2,
        title: 'Transparent approvals',
        description:
            'Every post moves through a clear status — draft, internal review, waiting on client, approved — so nobody ever has to ask "where are we on this?"',
    },
    {
        icon: ShieldCheck,
        title: 'A dedicated client review portal',
        description:
            'Clients approve or request changes from a clean, branded, link-based portal — no account required, no confusion about what they are looking at.',
    },
    {
        icon: Sparkles,
        title: 'AI-assisted content',
        description:
            'Generate on-brand post ideas, caption suggestions, and trend insights powered by your BrandBrain, so your team never starts from a blank page.',
    },
    {
        icon: PlugZap,
        title: 'Publish anywhere, from one place',
        description:
            'A pluggable publishing architecture sends approved posts straight to Instagram, TikTok, and more via Upload-Post, with status synced back automatically.',
    },
    {
        icon: Receipt,
        title: 'Simple client billing',
        description:
            'Generate, send, and track invoices per client without leaving the tool you already use to manage their content.',
    },
    {
        icon: BarChart3,
        title: 'Client health at a glance',
        description:
            'A Red/Amber/Green health signal keeps account owners ahead of at-risk clients before they become a problem.',
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
            'Your team reviews internally, then clients approve or request changes from their own portal.',
    },
    {
        step: '04',
        title: 'Publish & track',
        description:
            'Approved posts go live on schedule, and every status is tracked back to a single source of truth.',
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
            <span className="eyebrow text-muted-foreground">{eyebrow}</span>
            <h2 className="[&_em]:text-primary max-w-3xl font-serif text-4xl leading-[0.95] tracking-[-0.02em] lg:text-5xl [&_em]:italic">
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
                                    Open studio
                                    <ArrowRight />
                                </Link>
                            </Button>
                        ) : (
                            <>
                                <Button variant="ghost" asChild>
                                    <Link href={login()}>Log in</Link>
                                </Button>
                                <Button variant="secondary" asChild>
                                    <Link href={register()}>Get started</Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </header>

                <main className="relative mx-auto flex w-full max-w-6xl flex-1 flex-col gap-28 px-6 pt-14 pb-24 lg:pt-24">
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
                            Graphy brings clients, brands, campaigns, approvals,
                            AI assistance, and publishing together in one
                            dependable workspace — built for design &amp;
                            marketing agencies that juggle dozens of clients at
                            once.
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
                        <div className="border-border bg-border grid w-full grid-cols-1 gap-px overflow-hidden rounded-2xl border sm:grid-cols-2 lg:grid-cols-4">
                            {pillars.map(
                                ({ icon: Icon, title, description }) => (
                                    <div
                                        key={title}
                                        className="bg-card hover:bg-muted/40 flex flex-col items-start gap-3 p-6 transition-colors"
                                    >
                                        <Icon className="text-primary size-5" />
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
                                    From brief to published,{' '}
                                    <em>without the chaos</em>.
                                </>
                            }
                        />
                        <ol className="grid w-full grid-cols-1 gap-10 sm:grid-cols-2 lg:grid-cols-4">
                            {workflow.map(({ step, title, description }) => (
                                <li
                                    key={step}
                                    className="border-foreground flex flex-col gap-3 border-t pt-5"
                                >
                                    <span className="text-primary font-mono text-xs">
                                        {step}
                                    </span>
                                    <h3 className="font-serif text-3xl leading-tight">
                                        {title}
                                    </h3>
                                    <p className="text-muted-foreground text-sm leading-relaxed">
                                        {description}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </section>

                    <section className="bg-ink text-ink-foreground relative flex flex-col gap-8 overflow-hidden rounded-[1.75rem] p-10 lg:flex-row lg:items-end lg:justify-between lg:p-14">
                        <BrandPoster className="pointer-events-none absolute -top-10 -right-10 w-64 opacity-20 lg:w-80" />
                        <div className="relative flex max-w-xl flex-col gap-4">
                            <h2 className="font-serif text-4xl leading-[0.95] tracking-[-0.02em] text-balance lg:text-6xl">
                                Stop chasing approvals in{' '}
                                <em className="text-primary">Slack threads</em>.
                            </h2>
                            <p className="text-ink-foreground/70 max-w-md text-[15px]">
                                Bring your clients, content, and approvals into
                                one dependable home.
                            </p>
                        </div>
                        <div className="relative flex flex-wrap items-center gap-3">
                            {!auth.user ? (
                                <>
                                    <Button size="lg" asChild>
                                        <Link href={register()}>
                                            Get started
                                            <ArrowRight />
                                        </Link>
                                    </Button>
                                    <Button
                                        size="lg"
                                        variant="outline"
                                        className="border-ink-foreground/25 text-ink-foreground hover:bg-ink-foreground/10 hover:text-ink-foreground bg-transparent"
                                        asChild
                                    >
                                        <Link href={login()}>Log in</Link>
                                    </Button>
                                </>
                            ) : (
                                <Button size="lg" asChild>
                                    <Link href={dashboard()}>
                                        Open studio
                                        <ArrowRight />
                                    </Link>
                                </Button>
                            )}
                        </div>
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
