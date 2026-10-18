import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** Chapter number from `lib/chapters.ts`, shown as the mono index in the sidebar. */
    number?: string;
    /** Sections inside the chapter (e.g. 04 Clients → Campaigns), indented under it. */
    subItems?: NavItem[];
};
