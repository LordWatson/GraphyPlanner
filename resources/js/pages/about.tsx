import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    BarChart3,
    CalendarClock,
    Coins,
    Command,
    FileCheck2,
    ImagePlus,
    LayoutGrid,
    Move,
    PlugZap,
    Receipt,
    ShieldCheck,
    Sparkles,
} from 'lucide-react';
import type { ReactNode } from 'react';

import { BrandMark } from '@/components/brand-mark';
import { BrandPattern, Peek } from '@/components/brand-poster';
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
            'Draft, schedule and see every post across every client on one calendar — then drag a post to a new day when plans change.',
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
            'Post ideas, captions with ready-to-use hashtags, trend insights and a daily brief — all grounded in each client’s Brand Brain and live campaigns.',
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
            'Generate, send and track invoices per client — in their currency — from a ledger that sits beside their content.',
    },
    {
        icon: BarChart3,
        title: 'Client health at a glance',
        description:
            'A Red / Amber / Green signal keeps account owners ahead of at-risk clients before they become a problem.',
    },
];

const recentlyShipped = [
    {
        icon: ImagePlus,
        title: 'AI Image Studio',
        description:
            'Turn a post idea into a set of on-brand images, guided by art direction and Brand Brain references. Refine, pick a final and drop it straight into the post — AI drafts, a human always chooses.',
    },
    {
        icon: Move,
        title: 'Drag-to-reschedule',
        description:
            'Move a post to another day right on the month or week calendar. Every channel shifts together, and nothing moves until you confirm.',
    },
    {
        icon: Coins,
        title: 'Multi-currency invoicing',
        description:
            'Bill each client in their own currency, see totals per currency, and duplicate last month’s invoice in a click.',
    },
    {
        icon: Command,
        title: 'Command palette',
        description:
            'Press ⌘K (or Ctrl K) anywhere in the planner to jump to any part of the studio, add a client or switch themes without reaching for the mouse.',
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
            'Build campaigns, draft posts, and let AI suggest ideas, captions and on-brand images that fit the brief.',
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
            <div className="bg-background text-foreground relative flex min-h-screen flex-col">
                <header className="relative mx-auto flex h-[88px] w-full max-w-[1280px] items-center justify-between px-5 md:px-10 lg:px-20">
                    <Link href={home()} aria-label="Alima home">
                        <BrandMark size="md" wordmark />
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
                    <section className="animate-rise border-foreground relative flex min-h-[480px] flex-col justify-center gap-8 overflow-hidden border-b-2 pb-56 sm:pb-64 lg:pb-24">
                        <Eyebrow dash className="text-primary">
                            About the planner
                        </Eyebrow>
                        <h1 className="font-display max-w-4xl text-[clamp(3rem,6.5vw,4.5rem)] leading-[1.04]">
                            Social content, run end to end
                            <span className="text-signal">.</span>
                        </h1>
                        <p className="text-muted-foreground max-w-2xl text-lg leading-relaxed text-pretty">
                            Alima Planner brings clients, brands, campaigns,
                            approvals, AI assistance and publishing together in
                            one workspace — built for a studio that runs dozens
                            of clients at once.
                        </p>
                        <Peek className="text-lilac right-0 w-[min(92%,30rem)] lg:-right-4" />
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
                            eyebrow="Recently shipped"
                            title={
                                <>
                                    New tools, <em>same</em> calm workspace.
                                </>
                            }
                        />
                        <div className="grid w-full grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {recentlyShipped.map(
                                ({ icon: Icon, title, description }) => (
                                    <div
                                        key={title}
                                        className="bg-card border-border/70 flex flex-col items-start gap-3 rounded-2xl border p-6 shadow-sm"
                                    >
                                        <div className="flex w-full items-center justify-between">
                                            <span className="bg-accent text-accent-foreground flex size-10 items-center justify-center rounded-full">
                                                <Icon className="size-5" />
                                            </span>
                                            <span className="text-primary font-mono text-xs font-medium">
                                                New
                                            </span>
                                        </div>
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

                <footer className="eyebrow text-muted-foreground mx-auto flex w-full max-w-[1280px] justify-between px-5 py-6 md:px-10 lg:px-20">
                    <span>© {new Date().getFullYear()} Alima</span>
                    <Link href={about()} className="hover:text-foreground">
                        About
                    </Link>
                </footer>
            </div>
        </>
    );
}
