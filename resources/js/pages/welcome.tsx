import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarClock, LayoutGrid, ShieldCheck } from 'lucide-react';

import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

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
                    className="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[560px] bg-gradient-brand opacity-[0.12] blur-3xl"
                />

                <header className="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-6">
                    <div className="flex items-center gap-2">
                        <span className="flex size-9 items-center justify-center rounded-lg bg-gradient-brand shadow-lg shadow-primary/20">
                            <AppLogoIcon className="size-5 fill-current text-white" />
                        </span>
                        <span className="text-lg font-semibold">Graphy</span>
                    </div>
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

                <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col items-center gap-16 px-6 py-12 text-center lg:py-24">
                    <div className="flex max-w-2xl flex-col items-center gap-6">
                        <span className="rounded-full border border-primary/20 bg-primary/10 px-3 py-1 text-xs font-medium text-primary">
                            Built for design &amp; marketing agencies
                        </span>
                        <h1 className="text-4xl font-bold tracking-tight text-balance lg:text-5xl">
                            Social content planning,{' '}
                            <span className="text-gradient-brand">made for agencies.</span>
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
                                className="flex flex-col items-center gap-3 rounded-xl border border-border bg-card p-6 text-left shadow-lg shadow-black/[0.03] transition-shadow hover:shadow-primary/10"
                            >
                                <span className="flex size-9 items-center justify-center rounded-md bg-gradient-brand text-white shadow-sm">
                                    <Icon className="size-4" />
                                </span>
                                <h2 className="text-sm font-semibold">{title}</h2>
                                <p className="text-xs text-muted-foreground">{description}</p>
                            </div>
                        ))}
                    </div>
                </main>

                <footer className="mx-auto w-full max-w-5xl px-6 py-6 text-center text-xs text-muted-foreground">
                    &copy; {new Date().getFullYear()} Graphy. All rights reserved.
                </footer>
            </div>
        </>
    );
}
