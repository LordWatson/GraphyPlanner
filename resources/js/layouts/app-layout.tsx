import { usePage } from '@inertiajs/react';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import ClientPortalLayout from '@/layouts/client-portal/client-portal-layout';
import type { Auth, BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const { auth } = usePage<{ auth: Auth }>().props;

    // Client portal contacts never see the internal ink-sidebar shell — shared pages they can
    // reach (Settings) render inside the client portal chrome instead.
    if (auth.user?.role === 'client_reviewer') {
        return <ClientPortalLayout>{children}</ClientPortalLayout>;
    }

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {children}
        </AppLayoutTemplate>
    );
}
