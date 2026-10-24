import { Link } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { Peek } from '@/components/brand-poster';
import { FeedbackDialog } from '@/components/feedback-dialog';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useNavGroups } from '@/hooks/use-nav-groups';

export function AppSidebar() {
    const { groups, homeHref } = useNavGroups();

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="px-3 pt-4 pb-3">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            asChild
                            className="hover:bg-transparent active:bg-transparent"
                        >
                            <Link href={homeHref} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-3 overflow-x-hidden pt-1">
                {groups.map((group) => (
                    <NavMain
                        key={group.label}
                        items={group.items}
                        label={group.label}
                    />
                ))}
            </SidebarContent>

            <SidebarFooter className="gap-3 p-3">
                {/* The peek (§5.5): tonal teal-on-teal, peeking over a hairline — never behind text. */}
                <div
                    aria-hidden
                    className="border-sidebar-border relative mx-2 h-24 overflow-hidden border-b group-data-[collapsible=icon]:hidden"
                >
                    <Peek className="text-sidebar-accent left-1/2 w-[78%] -translate-x-1/2 translate-y-[38%]" />
                </div>
                <p className="eyebrow text-sidebar-foreground/65 px-2 pb-1 group-data-[collapsible=icon]:hidden">
                    Marketing brighter together.
                </p>
                {/* Step M-8.2: staff "Send feedback" (renders only for internal roles). */}
                <FeedbackDialog />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
