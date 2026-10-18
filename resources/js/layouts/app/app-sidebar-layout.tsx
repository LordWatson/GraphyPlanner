import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { CommandPaletteProvider } from '@/components/command-palette';
import { PreviewBanner } from '@/components/preview-banner';
import { usePreviewGuard } from '@/hooks/use-preview-guard';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    usePreviewGuard();

    return (
        <AppShell variant="sidebar">
            <CommandPaletteProvider>
                <AppSidebar />
                <AppContent
                    variant="sidebar"
                    className="min-w-0 overflow-x-clip"
                >
                    <PreviewBanner />
                    <AppSidebarHeader breadcrumbs={breadcrumbs} />
                    {children}
                </AppContent>
            </CommandPaletteProvider>
        </AppShell>
    );
}
