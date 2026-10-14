import { Head, Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    CalendarClock,
    FileCheck2,
    Layers,
    LayoutGrid,
    PlugZap,
    Receipt,
    ShieldCheck,
    Sparkles,
} from 'lucide-react';

import AppLogoIcon from '@/components/app-logo-icon';
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
        description: 'Add clients, define their brand voice, and connect their social accounts.',
    },
    {
        step: '02',
        title: 'Plan the work',
        description: 'Build campaigns, draft posts, and let AI suggest ideas and captions that fit the brand.',
    },
    {
        step: '03',
        title: 'Review & approve',
        description: 'Your team reviews internally, then clients approve or request changes from their own portal.',
    },
    {
        step: '04',
        title: 'Publish & track',
        description: 'Approved posts go live on schedule, and every status is tracked back to a single source of truth.',
    },
];

export default function About() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="About" />
            <div className="relative flex min-h-screen flex-col overflow-hidden bg-background text-foreground">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[560px] bg-gradient-brand opacity-[0.12] blur-3xl"
                />

                <header className="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-6">
                    <Link href={home()} className="flex items-center gap-2">
                        <span className="flex size-9 items-center justify-center rounded-lg bg-gradient-brand shadow-lg shadow-primary/20">
                            <AppLogoIcon className="size-5 fill-current text-white" />
                        </span>
                        <span className="text-lg font-semibold">Graphy</span>
                    </Link>
                    <nav className="flex items-center gap-2">
                        {auth.user ? (
                            <Button asChild>
                                <Link href={dashboard()}>Dashboard</Link>
                            </Button>
                        ) : (
                            <>
                                <Button variant="ghost" asChild>
                                    <Link href={login()}>Log in</Link>
                                </Button>
                                <Button asChild>
                                    <Link href={register()}>Get started</Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </header>

                <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-20 px-6 py-12 lg:py-20">
                    <div className="flex flex-col items-center gap-6 text-center">
                        <span className="rounded-full border border-primary/20 bg-primary/10 px-3 py-1 text-xs font-medium text-primary">
                            About Graphy
                        </span>
                        <h1 className="max-w-2xl text-4xl font-bold tracking-tight text-balance lg:text-5xl">
                            The <span className="text-gradient-brand">agency OS</span> for running
                            social content, end to end.
                        </h1>
                        <p className="max-w-2xl text-sm text-muted-foreground lg:text-base">
                            Graphy brings clients, brands, campaigns, approvals, AI assistance, and
                            publishing together in one dependable workspace — built for design &amp;
                            marketing agencies that juggle dozens of clients at once.
                        </p>
                        {!auth.user && (
                            <div className="flex items-center gap-3">
                                <Button size="lg" className="shadow-lg shadow-primary/25" asChild>
                                    <Link href={register()}>Get started</Link>
                                </Button>
                                <Button size="lg" variant="outline" asChild>
                                    <Link href={about()}>See what&apos;s inside</Link>
                                </Button>
                            </div>
                        )}
                    </div>

                    <section className="flex flex-col gap-8">
                        <div className="flex flex-col items-center gap-2 text-center">
                            <span className="text-[10px] font-medium tracking-widest text-muted-foreground uppercase">
                                Everything you need
                            </span>
                            <h2 className="text-2xl font-serif font-semibold tracking-tight">
                                One workspace, every part of the job.
                            </h2>
                        </div>
                        <div className="grid w-full gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {pillars.map(({ icon: Icon, title, description }) => (
                                <div
                                    key={title}
                                    className="flex flex-col items-start gap-3 rounded-xl border border-border bg-card p-6 text-left shadow-lg shadow-black/[0.03] transition-shadow hover:shadow-primary/10"
                                >
                                    <span className="flex size-9 items-center justify-center rounded-md bg-gradient-brand text-white shadow-sm">
                                        <Icon className="size-4" />
                                    </span>
                                    <h3 className="text-sm font-semibold">{title}</h3>
                                    <p className="text-xs text-muted-foreground">{description}</p>
                                </div>
                            ))}
                        </div>
                    </section>

                    <section className="flex flex-col gap-8">
                        <div className="flex flex-col items-center gap-2 text-center">
                            <span className="text-[10px] font-medium tracking-widest text-muted-foreground uppercase">
                                How it works
                            </span>
                            <h2 className="text-2xl font-serif font-semibold tracking-tight">
                                From brief to published, without the chaos.
                            </h2>
                        </div>
                        <div className="grid w-full gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {workflow.map(({ step, title, description }) => (
                                <div
                                    key={step}
                                    className="flex flex-col gap-3 rounded-xl border border-border bg-card p-6 text-left"
                                >
                                    <span className="font-serif text-2xl font-semibold text-primary">
                                        {step}
                                    </span>
                                    <h3 className="text-sm font-semibold">{title}</h3>
                                    <p className="text-xs text-muted-foreground">{description}</p>
                                </div>
                            ))}
                        </div>
                    </section>

                    <section className="flex flex-col items-center gap-6 rounded-2xl border border-border bg-gradient-brand p-10 text-center shadow-lg shadow-primary/10 lg:p-16">
                        <span className="flex size-10 items-center justify-center rounded-full bg-background/90 text-primary shadow-sm">
                            <Layers className="size-5" />
                        </span>
                        <h2 className="max-w-xl text-2xl font-serif font-semibold tracking-tight text-balance text-background lg:text-3xl">
                            Ready to stop chasing approvals in Slack threads?
                        </h2>
                        <p className="max-w-md text-sm text-background/80">
                            Bring your clients, content, and approvals into one dependable home.
                        </p>
                        {!auth.user ? (
                            <div className="flex items-center gap-3">
                                <Button size="lg" variant="secondary" className="shadow-lg" asChild>
                                    <Link href={register()}>Get started</Link>
                                </Button>
                                <Button
                                    size="lg"
                                    variant="outline"
                                    className="border-background/40 bg-transparent text-background hover:bg-background/10 hover:text-background"
                                    asChild
                                >
                                    <Link href={login()}>Log in</Link>
                                </Button>
                            </div>
                        ) : (
                            <Button size="lg" variant="secondary" className="shadow-lg" asChild>
                                <Link href={dashboard()}>Go to dashboard</Link>
                            </Button>
                        )}
                    </section>
                </main>

                <footer className="mx-auto w-full max-w-5xl px-6 py-6 text-center text-xs text-muted-foreground">
                    &copy; {new Date().getFullYear()} Graphy. All rights reserved.
                </footer>
            </div>
        </>
    );
}
