import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarClock, LayoutGrid, ShieldCheck } from 'lucide-react';

import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { about, dashboard, login, register } from '@/routes';

const features = [
    {
        icon: LayoutGrid,
        title: 'One place for every client',
        description:
            'Manage clients, brands, and campaigns side by side without losing track of what belongs where.',
    },
    {
        icon: CalendarClock,
        title: 'Plan and schedule with confidence',
        description:
            'Draft, review, and schedule social content on a shared calendar your whole team can trust.',
    },
    {
        icon: ShieldCheck,
        title: 'Clear approvals, every time',
        description:
            'Status is always legible — clients and teammates always know exactly where a post stands.',
    },
];

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Welcome" />
            <div className="relative flex min-h-screen flex-col overflow-hidden bg-background text-foreground">
                <div
                    aria-hidden
                    className="bg-gradient-brand-animated pointer-events-none absolute inset-x-0 top-0 -z-10 h-[620px] opacity-[0.16] blur-3xl"
                />
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(oklch(0.65_0.19_40_/_0.08)_1px,transparent_1px)] bg-[size:28px_28px] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_0%,black,transparent)]"
                />

                <header className="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-6">
                    <div className="flex items-center gap-2">
                        <span className="bg-noise bg-gradient-brand-animated shadow-agency flex size-9 items-center justify-center overflow-hidden rounded-lg">
                            <AppLogoIcon className="size-5 fill-current text-white" />
                        </span>
                        <span className="font-serif text-lg font-semibold italic">Graphy</span>
                    </div>
                    <nav className="flex items-center gap-2">
                        <Button variant="ghost" asChild>
                            <Link href={about()}>About</Link>
                        </Button>
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

                <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col items-center gap-16 px-6 py-12 text-center lg:py-24">
                    <div className="flex max-w-2xl flex-col items-center gap-6">
                        <span className="inline-flex items-center gap-2 rounded-full border border-primary/20 bg-primary/10 px-3 py-1 text-xs font-medium tracking-wide text-primary uppercase">
                            <span className="size-1.5 rounded-full bg-primary" />
                            Built for design &amp; marketing agencies
                        </span>
                        <h1 className="font-serif text-5xl font-semibold tracking-tight text-balance lg:text-7xl">
                            Social content planning,{' '}
                            <span className="bg-gradient-brand-animated bg-clip-text text-transparent italic">
                                made for agencies.
                            </span>
                        </h1>
                        <p className="text-sm text-muted-foreground lg:text-base">
                            Graphy keeps clients, brands, campaigns, and scheduled posts organized in
                            one dependable workspace — so your team can focus on the work,
                            not the tracking.
                        </p>
                        {!auth.user && (
                            <div className="flex items-center gap-3">
                                <Button size="lg" className="shadow-lg shadow-primary/25" asChild>
                                    <Link href={register()}>Get started</Link>
                                </Button>
                                <Button size="lg" variant="outline" asChild>
                                    <Link href={login()}>Log in</Link>
                                </Button>
                            </div>
                        )}
                    </div>

                    <div className="grid w-full gap-4 sm:grid-cols-3">
                        {features.map(({ icon: Icon, title, description }) => (
                            <div
                                key={title}
                                className="group relative flex flex-col items-center gap-3 overflow-hidden rounded-xl border border-border bg-card p-6 text-left shadow-agency transition-transform duration-200 hover:-translate-y-1"
                            >
                                <span
                                    aria-hidden
                                    className="bg-gradient-brand absolute inset-x-0 top-0 h-1 opacity-80 transition-opacity group-hover:opacity-100"
                                />
                                <span className="bg-noise flex size-9 items-center justify-center overflow-hidden rounded-md bg-gradient-brand text-white shadow-sm">
                                    <Icon className="size-4" />
                                </span>
                                <h2 className="text-sm font-semibold">{title}</h2>
                                <p className="text-xs text-muted-foreground">{description}</p>
                            </div>
                        ))}
                    </div>
                </main>

                <footer className="mx-auto flex w-full max-w-5xl flex-col items-center gap-2 px-6 py-6 text-center text-xs text-muted-foreground">
                    <Link href={about()} className="font-medium text-foreground hover:text-primary">
                        Learn more about Graphy
                    </Link>
                    <span>&copy; {new Date().getFullYear()} Graphy. All rights reserved.</span>
                </footer>
            </div>
        </>
    );
}
