import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    CalendarDays,
    CheckCheck,
    UsersRound,
} from 'lucide-react';

import { BrandMark } from '@/components/brand-mark';
import { CampaignsSection } from '@/components/marketing/campaigns-section';
import { Peek } from '@/components/brand-poster';
import { Eyebrow } from '@/components/page';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { about, dashboard, login, register } from '@/routes';

const features = [
    {
        icon: UsersRound,
        title: 'Every client, one roster',
        description:
            'Clients, brands, campaigns and retainers side by side — with health you can read at a glance.',
    },
    {
        icon: CalendarDays,
        title: 'A calendar the team trusts',
        description:
            'Draft, review and schedule across every platform and timezone on one shared calendar.',
    },
    {
        icon: CheckCheck,
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
            <div className="bg-background text-foreground relative flex min-h-screen flex-col">
                <header className="relative mx-auto flex h-[88px] w-full max-w-[1280px] items-center justify-between px-5 md:px-10 lg:px-20">
                    <BrandMark size="md" wordmark />
                    <nav className="flex items-center gap-1.5">
                        <Button
                            variant="ghost"
                            asChild
                            className="hidden sm:inline-flex"
                        >
                            <Link href={about()}>About</Link>
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
                                    <Link href={register()}>
                                        Let&apos;s talk
                                    </Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </header>

                <main className="relative mx-auto flex w-full max-w-[1280px] flex-1 flex-col gap-24 px-5 pb-24 md:px-10 lg:px-20">
                    <section className="animate-rise border-foreground relative flex min-h-[560px] flex-col justify-center gap-8 overflow-hidden border-b-2 pt-10 pb-56 sm:pb-64 lg:pb-24">
                        <Eyebrow dash className="text-primary">
                            Alima Planner
                        </Eyebrow>
                        <h1 className="font-display max-w-4xl text-[clamp(3.5rem,8vw,6rem)] leading-[1.02]">
                            Every post, planned{' '}
                            <span className="text-signal">brighter.</span>
                        </h1>
                        <p className="text-muted-foreground max-w-xl text-lg leading-relaxed text-pretty">
                            Clients, brands, campaigns and scheduled posts in
                            one bright workspace — so the team spends its time
                            on the work, not on tracking it.
                        </p>
                        {!auth.user && (
                            <div className="flex flex-wrap items-center gap-3">
                                <Button size="lg" variant="spark" asChild>
                                    <Link href={register()}>
                                        Let&apos;s grow
                                        <ArrowRight />
                                    </Link>
                                </Button>
                                <Button size="lg" variant="outline" asChild>
                                    <Link href={about()}>See the work</Link>
                                </Button>
                            </div>
                        )}
                        <Peek className="text-lilac right-0 w-[min(92%,30rem)] lg:-right-4" />
                    </section>

                    <section
                        aria-label="A week in Alima Planner"
                        className="animate-rise border-border/70 bg-card rounded-2xl border p-3 shadow-md [animation-delay:80ms]"
                    >
                        <div className="flex items-center justify-between px-3 pt-2 pb-4">
                            <span className="font-display text-xl">
                                This <span className="text-primary">week</span>.
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
                                        <span className="font-display text-2xl leading-none font-bold">
                                            {day.date}
                                        </span>
                                    </div>
                                    {day.posts.map((post) => (
                                        <div
                                            key={post.client + post.status}
                                            className="border-border/70 bg-card flex flex-col gap-1.5 rounded-lg border p-2"
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
                        {features.map(
                            ({ icon: Icon, title, description }, i) => (
                                <div
                                    key={title}
                                    className="border-foreground flex flex-col gap-4 border-t-2 pt-6"
                                >
                                    <div className="flex items-center justify-between">
                                        <span className="bg-primary text-primary-foreground flex size-12 items-center justify-center rounded-full">
                                            <Icon className="size-6" />
                                        </span>
                                        <span className="text-muted-foreground font-mono text-xs">
                                            {String(i + 1).padStart(2, '0')}
                                        </span>
                                    </div>
                                    <h2 className="font-display text-2xl leading-[1.15]">
                                        {title}
                                    </h2>
                                    <p className="text-muted-foreground text-[15px] leading-relaxed">
                                        {description}
                                    </p>
                                </div>
                            ),
                        )}
                    </section>

                    <CampaignsSection />
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
                                More about the planner
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
