import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { ChapterHero, Page } from '@/components/page';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAiUsage } from '@/routes/ai-usage';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editOrganization } from '@/routes/organization';
import { edit as editNotifications } from '@/routes/notifications';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { edit as editWorkspace } from '@/routes/workspace-settings';
import type { Auth, NavItem } from '@/types';

type WorkspaceProp = { type: string } | null;

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
        title: 'Notifications',
        href: editNotifications(),
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
    const { auth, workspace } = usePage<{
        auth: Auth;
        workspace: WorkspaceProp;
    }>().props;

    const isOwner = auth.user.role === 'owner';
    const isBusiness = workspace?.type === 'business';

    // Step B-3.3: a business owner edits the workspace's own settings (the review switch) in place
    // of the agency-only Organization page (API keys, invoicing — concepts a business org doesn't
    // have); AI usage stays for both.
    const navItems: NavItem[] = isOwner
        ? [
              ...sidebarNavItems,
              isBusiness
                  ? {
                        title: 'Workspace',
                        href: editWorkspace(),
                        icon: null,
                    }
                  : {
                        title: 'Organization',
                        href: editOrganization(),
                        icon: null,
                    },
              {
                  title: 'AI usage',
                  href: editAiUsage(),
                  icon: null,
              },
          ]
        : sidebarNavItems;

    return (
        <Page>
            <ChapterHero
                eyebrow="Account & studio"
                title={
                    <>
                        Your <em>account</em>.
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
                                        'flex h-10 shrink-0 items-center gap-3 rounded-full px-4 text-sm transition-colors',
                                        active
                                            ? 'bg-foreground text-background font-semibold'
                                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'hidden font-mono text-[11px] tabular-nums lg:inline',
                                            active
                                                ? 'opacity-60'
                                                : 'text-muted-foreground',
                                        )}
                                    >
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
