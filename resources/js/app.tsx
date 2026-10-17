import { createInertiaApp } from '@inertiajs/react';
import { ConfirmDialogHost } from '@/components/confirm-dialog';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import ClientPortalLayout from '@/layouts/client-portal/client-portal-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Alima';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name === 'about':
                return null;
            case name.startsWith('review/'):
                return null;
            case name.startsWith('client-invite/'):
                return null;
            case name.startsWith('staff-invite/'):
                return null;
            // Phase 15: the public org signup is an auth page; the "suspended" lock-out page
            // stands alone (`PublicShell`) — a locked-out member gets no app chrome.
            case name === 'organizations/register':
                return AuthLayout;
            case name === 'organizations/suspended':
                return null;
            case name.startsWith('portal/'):
                return ClientPortalLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
                <ConfirmDialogHost />
            </TooltipProvider>
        );
    },
    progress: {
        // Deep Teal — the progress bar can't read CSS tokens.
        color: '#205557',
    },
});

// This will set light / dark mode on load...
initializeTheme();
