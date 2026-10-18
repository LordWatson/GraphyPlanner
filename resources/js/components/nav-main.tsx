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
 * The sidebar reads like the book's Contents page (§00): a mono chapter number, then the title.
 * Collapsed to icons, the number gives way to the item's icon. The active chapter is an Ink-on-
 * Lime pill (approved pairing, 13:1).
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
                                    <item.icon
                                        className={
                                            item.number
                                                ? 'hidden group-data-[collapsible=icon]:block'
                                                : undefined
                                        }
                                    />
                                )}
                                {item.number && (
                                    <span
                                        aria-hidden
                                        className="w-5 shrink-0 font-mono text-[11px] tracking-[0.04em] tabular-nums opacity-60 group-data-[collapsible=icon]:hidden"
                                    >
                                        {item.number}
                                    </span>
                                )}
                                <span>{item.title}</span>
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
