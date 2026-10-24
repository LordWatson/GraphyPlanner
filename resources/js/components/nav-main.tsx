import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

/**
 * The sidebar reads like the book's Contents page (§00): the chapter icon and title, with the
 * chapter number set as a right-aligned mono index — exactly as a printed Contents lists a section
 * against its page number. Collapsed to icons, only the icon remains. The active chapter is an
 * Ink-on-Lime pill (approved pairing, 13:1).
 */
export function NavMain({
    items,
    label = 'Studio',
}: {
    items: NavItem[];
    label?: string;
}) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="px-3 py-2">
            <SidebarGroupLabel className="eyebrow text-sidebar-foreground/60 h-7 px-3">
                {label}
            </SidebarGroupLabel>
            <SidebarMenu className="gap-0.5">
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={
                                item.isActive ?? isCurrentOrParentUrl(item.href)
                            }
                            tooltip={{ children: item.title }}
                            className="text-sidebar-foreground/90 data-[active=true]:bg-sidebar-primary data-[active=true]:text-sidebar-primary-foreground data-[active=true]:[&>svg]:text-sidebar-primary-foreground h-10 gap-3 data-[active=true]:shadow-sm"
                        >
                            <Link href={item.href} prefetch>
                                {item.icon && (
                                    <item.icon className="shrink-0" />
                                )}
                                <span className="min-w-0 flex-1 truncate">
                                    {item.title}
                                </span>
                                {item.number && (
                                    <span
                                        aria-hidden
                                        className="shrink-0 font-mono text-[10.5px] tracking-[0.05em] tabular-nums opacity-45 group-data-[collapsible=icon]:hidden"
                                    >
                                        {item.number}
                                    </span>
                                )}
                            </Link>
                        </SidebarMenuButton>
                        {item.subItems && item.subItems.length > 0 && (
                            <SidebarMenuSub className="border-sidebar-foreground/20 mt-0.5">
                                {item.subItems.map((sub) => {
                                    const active =
                                        sub.isActive ??
                                        isCurrentOrParentUrl(sub.href);

                                    return (
                                        <SidebarMenuSubItem key={sub.title}>
                                            <SidebarMenuSubButton
                                                asChild
                                                isActive={active}
                                                className="text-sidebar-foreground/80 data-[active=true]:bg-sidebar-primary data-[active=true]:text-sidebar-primary-foreground h-8 rounded-full px-3 data-[active=true]:font-medium"
                                            >
                                                <Link href={sub.href} prefetch>
                                                    <span>{sub.title}</span>
                                                </Link>
                                            </SidebarMenuSubButton>
                                        </SidebarMenuSubItem>
                                    );
                                })}
                            </SidebarMenuSub>
                        )}
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
