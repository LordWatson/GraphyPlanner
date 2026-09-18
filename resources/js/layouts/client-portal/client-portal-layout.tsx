import { Form, Head, Link, usePage } from '@inertiajs/react';
import { CalendarCheck2, Receipt } from 'lucide-react';

import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';
import { dashboard as portalDashboard } from '@/routes/portal';
import { index as portalPostsIndex } from '@/routes/portal/posts';

/**
 * Step 6.3 — the client portal's shell. Deliberately distinct from `AppSidebarLayout`: no
 * internal-ops chrome (no "Needs attention", Settings, other clients) — just this client's
 * identity, a nav for Posts/Approvals/Invoices, and a way to sign out. Per the brand guidelines
 * §7, this reuses the same tokens/components as the internal app but in the lighter, simplified
 * variant used by the review portal (no dark sidebar).
 */
export default function ClientPortalLayout({
    title,
    children,
}: {
    title?: string;
    children: React.ReactNode;
}) {
    const { client } = usePage().props as unknown as { client?: { name: string } };

    const navItems = [
        { title: 'Dashboard', href: portalDashboard(), icon: CalendarCheck2 },
        { title: 'Posts & approvals', href: portalPostsIndex(), icon: CalendarCheck2 },
        { title: 'Invoices', href: portalDashboard(), icon: Receipt },
    ];

    return (
        <>
            <Head title={title ? `${title} — Client Portal` : 'Client Portal'} />
            <div className="relative flex min-h-screen flex-col bg-background text-foreground">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[280px] bg-gradient-brand opacity-[0.08] blur-3xl"
                />

                <header className="border-b border-border">
                    <div className="mx-auto flex w-full max-w-4xl flex-wrap items-center justify-between gap-4 px-6 py-5">
                        <div className="flex items-center gap-2">
                            <span className="flex size-9 items-center justify-center rounded-lg bg-gradient-brand shadow-lg shadow-primary/20">
                                <AppLogoIcon className="size-5 fill-current text-white" />
                            </span>
                            <div className="leading-tight">
                                <p className="font-serif text-lg font-semibold italic">graphy.</p>
                                {client && <p className="text-xs text-muted-foreground">{client.name}</p>}
                            </div>
                        </div>

                        <nav className="flex items-center gap-1">
                            {navItems.map((item) => (
                                <Link
                                    key={item.title}
                                    href={item.href}
                                    className="inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                >
                                    <item.icon className="size-4" />
                                    {item.title}
                                </Link>
                            ))}
                            <Form action={logout()} method="post">
                                {({ processing }) => (
                                    <Button type="submit" variant="ghost" size="sm" disabled={processing}>
                                        Log out
                                    </Button>
                                )}
                            </Form>
                        </nav>
                    </div>
                </header>

                <main className="mx-auto w-full max-w-4xl flex-1 px-6 py-8">{children}</main>

                <footer className="mx-auto w-full max-w-4xl px-6 py-6 text-center text-xs text-muted-foreground">
                    &copy; {new Date().getFullYear()} Graphy. All rights reserved.
                </footer>
            </div>
        </>
    );
}
