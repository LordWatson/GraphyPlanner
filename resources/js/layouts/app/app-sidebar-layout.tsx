import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { CommandPaletteProvider } from '@/components/command-palette';
import { PreviewBanner } from '@/components/preview-banner';
import { PwaProvider } from '@/components/pwa/pwa-provider';
import { usePreviewGuard } from '@/hooks/use-preview-guard';
import { useWorkspace } from '@/hooks/use-workspace';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    usePreviewGuard();
    const { isBusiness } = useWorkspace();

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
                {/* Step B-7.1: the installable PWA shell is business-only. */}
                {isBusiness && <PwaProvider />}
            </CommandPaletteProvider>
        </AppShell>
    );
}
