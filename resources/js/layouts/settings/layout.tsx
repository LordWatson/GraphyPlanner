import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { Page, PageHeader } from '@/components/page';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editOrganization } from '@/routes/organization';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { Auth, NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: edit(),
        icon: null,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: null,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: null,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { auth } = usePage<{ auth: Auth }>().props;

    const navItems: NavItem[] =
        auth.user.role === 'owner'
            ? [
                  ...sidebarNavItems,
                  {
                      title: 'Organization',
                      href: editOrganization(),
                      icon: null,
                  },
              ]
            : sidebarNavItems;

    return (
        <Page>
            <PageHeader
                eyebrow="Settings"
                title={
                    <>
                        Your <em>account</em>
                    </>
                }
                description="Profile, security, appearance and — for owners — the organisation itself."
            />

            <div className="flex flex-col gap-8 lg:flex-row lg:gap-14">
                <aside className="w-full shrink-0 lg:sticky lg:top-20 lg:w-52 lg:self-start">
                    <nav
                        className="flex gap-1 overflow-x-auto lg:flex-col lg:gap-0.5"
                        aria-label="Settings"
                    >
                        {navItems.map((item, index) => {
                            const active = isCurrentOrParentUrl(item.href);

                            return (
                                <Link
                                    key={`${toUrl(item.href)}-${index}`}
                                    href={item.href}
                                    aria-current={active ? 'page' : undefined}
                                    className={cn(
                                        'flex shrink-0 items-center gap-3 rounded-full px-3.5 py-2 text-sm transition-colors lg:rounded-lg',
                                        active
                                            ? 'bg-card text-foreground ring-border font-medium shadow-[inset_2px_0_0_var(--primary)] ring-1'
                                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                    )}
                                >
                                    <span className="text-muted-foreground hidden font-mono text-[11px] tabular-nums lg:inline">
                                        {String(index + 1).padStart(2, '0')}
                                    </span>
                                    {item.title}
                                </Link>
                            );
                        })}
                    </nav>
                </aside>

                <div className="min-w-0 flex-1 md:max-w-2xl">
                    <section className="animate-rise flex max-w-xl flex-col gap-12">
                        {children}
                    </section>
                </div>
            </div>
        </Page>
    );
}
