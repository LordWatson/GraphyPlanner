import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, ArrowUpRight } from 'lucide-react';

import { BrandMark } from '@/components/brand-mark';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { about, dashboard, login, register } from '@/routes';

const features = [
    {
        title: 'Every client, one roster',
        description:
            'Clients, brands, campaigns and retainers side by side — with health you can read at a glance.',
    },
    {
        title: 'A calendar the team trusts',
        description:
            'Draft, review and schedule across every platform and timezone on one shared calendar.',
    },
    {
        title: 'Approvals without the chase',
        description:
            'Clients approve from a clean, branded link. Everyone always knows where a post stands.',
    },
];

const weekStrip = [
    {
        day: 'Mon',
        date: '12',
        posts: [
            { client: 'Northwind', status: 'published' },
            { client: 'Fable & Co', status: 'published' },
        ],
    },
    {
        day: 'Tue',
        date: '13',
        posts: [{ client: 'Lumen Studio', status: 'scheduled' }],
    },
    {
        day: 'Wed',
        date: '14',
        posts: [
            { client: 'Northwind', status: 'waiting_client' },
            { client: 'Oro Coffee', status: 'scheduled' },
        ],
    },
    {
        day: 'Thu',
        date: '15',
        posts: [{ client: 'Fable & Co', status: 'changes_requested' }],
    },
    {
        day: 'Fri',
        date: '16',
        posts: [{ client: 'Oro Coffee', status: 'approved' }],
    },
];

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Welcome" />
            <div className="bg-background text-foreground relative flex min-h-screen flex-col overflow-hidden">
                <div
                    aria-hidden
                    className="bg-grid text-foreground pointer-events-none absolute inset-x-0 top-0 -z-0 h-[720px] [mask-image:radial-gradient(ellipse_70%_60%_at_50%_0%,black,transparent)]"
                />

                <header className="relative mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-6">
                    <BrandMark size="sm" wordmark />
                    <nav className="flex items-center gap-1.5">
                        <Button variant="ghost" asChild>
                            <Link href={about()}>About</Link>
                        </Button>
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

                <main className="relative mx-auto flex w-full max-w-6xl flex-1 flex-col gap-24 px-6 pt-14 pb-20 lg:pt-24">
                    <section className="animate-rise grid grid-cols-1 items-end gap-12 lg:grid-cols-[1.35fr_1fr]">
                        <div className="flex flex-col gap-8">
                            <span className="eyebrow text-muted-foreground flex items-center gap-2">
                                <span
                                    aria-hidden
                                    className="bg-primary size-1.5 rounded-[2px]"
                                />
                                The operating system for social studios
                            </span>
                            <h1 className="font-serif text-[clamp(3.5rem,9vw,7.5rem)] leading-[0.88] tracking-[-0.035em]">
                                Social content,
                                <br />
                                <em className="text-primary">orchestrated.</em>
                            </h1>
                        </div>
                        <div className="flex flex-col gap-6 lg:pb-3">
                            <p className="text-muted-foreground text-lg leading-relaxed text-pretty">
                                Graphy keeps clients, brands, campaigns and
                                scheduled posts in one considered workspace — so
                                your team spends its time on the work, not on
                                tracking it.
                            </p>
                            {!auth.user && (
                                <div className="flex flex-wrap items-center gap-3">
                                    <Button size="lg" asChild>
                                        <Link href={register()}>
                                            Start planning
                                            <ArrowRight />
                                        </Link>
                                    </Button>
                                    <Button size="lg" variant="outline" asChild>
                                        <Link href={login()}>Log in</Link>
                                    </Button>
                                </div>
                            )}
                        </div>
                    </section>

                    <section
                        aria-label="A week in Graphy"
                        className="animate-rise border-border bg-card shadow-paper rounded-[1.5rem] border p-3 [animation-delay:120ms]"
                    >
                        <div className="flex items-center justify-between px-3 pt-2 pb-4">
                            <span className="font-serif text-2xl">
                                This <em className="text-primary">week</em>
                            </span>
                            <span className="eyebrow text-muted-foreground">
                                5 clients · 8 posts
                            </span>
                        </div>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                            {weekStrip.map((day) => (
                                <div
                                    key={day.day}
                                    className="border-border/70 bg-background flex min-h-40 flex-col gap-2 rounded-xl border p-3"
                                >
                                    <div className="flex items-baseline justify-between">
                                        <span className="eyebrow text-muted-foreground">
                                            {day.day}
                                        </span>
                                        <span className="font-serif text-3xl leading-none">
                                            {day.date}
                                        </span>
                                    </div>
                                    {day.posts.map((post) => (
                                        <div
                                            key={post.client + post.status}
                                            className="border-border bg-card flex flex-col gap-1.5 rounded-lg border p-2"
                                        >
                                            <span className="text-xs font-medium">
                                                {post.client}
                                            </span>
                                            <StatusPill
                                                kind="post"
                                                value={post.status}
                                            />
                                        </div>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </section>

                    <section className="grid grid-cols-1 gap-10 sm:grid-cols-3">
                        {features.map(({ title, description }, i) => (
                            <div
                                key={title}
                                className="border-foreground flex flex-col gap-3 border-t pt-5"
                            >
                                <span className="text-primary font-mono text-xs">
                                    {String(i + 1).padStart(2, '0')}
                                </span>
                                <h2 className="font-serif text-3xl leading-tight">
                                    {title}
                                </h2>
                                <p className="text-muted-foreground text-sm leading-relaxed">
                                    {description}
                                </p>
                            </div>
                        ))}
                    </section>
                </main>

                <footer className="bg-ink text-ink-foreground relative">
                    <div className="mx-auto flex w-full max-w-6xl flex-col gap-10 px-6 pt-14 pb-8">
                        <div className="flex flex-wrap items-end justify-between gap-6">
                            <p className="font-serif text-[clamp(3rem,10vw,8rem)] leading-none tracking-[-0.03em] italic">
                                graphy
                                <span className="text-primary not-italic">
                                    .
                                </span>
                            </p>
                            <Link
                                href={about()}
                                className="group text-ink-foreground/80 hover:text-ink-foreground inline-flex items-center gap-1.5 text-sm"
                            >
                                More about Graphy
                                <ArrowUpRight className="size-4 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
                            </Link>
                        </div>
                        <div className="eyebrow border-ink-foreground/15 text-ink-foreground/50 flex justify-between border-t pt-4">
                            <span>© {new Date().getFullYear()} Graphy</span>
                            <span>Plan · Approve · Publish</span>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
