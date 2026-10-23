import { Download, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';

/**
 * Step B-7.1 — turns the business shell into an installable PWA. Mounted only inside the business
 * sidebar layout, so agency pages are untouched: the manifest link and theme-color are injected
 * here (not in the shared Blade root), the service worker is registered, and the browser's
 * `beforeinstallprompt` is captured so we can offer a tasteful, dismissible install card instead of
 * leaving the raw mini-infobar.
 *
 * No offline writes — the service worker only caches a shell and falls back to /offline.html.
 */

const DISMISS_KEY = 'alima.pwa.install-dismissed';

type InstallPromptEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

function ensureHead(manifestHref: string): void {
    if (typeof document === 'undefined') {
        return;
    }

    if (!document.querySelector('link[rel="manifest"]')) {
        const link = document.createElement('link');
        link.rel = 'manifest';
        link.href = manifestHref;
        document.head.appendChild(link);
    }

    if (!document.querySelector('meta[name="theme-color"]')) {
        const meta = document.createElement('meta');
        meta.name = 'theme-color';
        meta.content = '#205557';
        document.head.appendChild(meta);
    }
}

function dismissed(): boolean {
    try {
        return localStorage.getItem(DISMISS_KEY) === '1';
    } catch {
        return false;
    }
}

export function PwaProvider({
    manifestHref = '/manifest.webmanifest',
    title = 'Install Alima',
    description = 'Add it to your home screen to post in a tap, straight from your phone.',
}: {
    manifestHref?: string;
    title?: string;
    description?: string;
} = {}) {
    const [installEvent, setInstallEvent] = useState<InstallPromptEvent | null>(
        null,
    );
    const registered = useRef(false);

    useEffect(() => {
        ensureHead(manifestHref);

        if ('serviceWorker' in navigator && !registered.current) {
            registered.current = true;
            // Registration failure is non-fatal — the app works fine without the offline shell.
            navigator.serviceWorker.register('/sw.js').catch(() => {});
        }

        const onPrompt = (event: Event) => {
            event.preventDefault();

            if (!dismissed()) {
                setInstallEvent(event as InstallPromptEvent);
            }
        };

        const onInstalled = () => setInstallEvent(null);

        window.addEventListener('beforeinstallprompt', onPrompt);
        window.addEventListener('appinstalled', onInstalled);

        return () => {
            window.removeEventListener('beforeinstallprompt', onPrompt);
            window.removeEventListener('appinstalled', onInstalled);
        };
    }, [manifestHref]);

    const dismiss = () => {
        try {
            localStorage.setItem(DISMISS_KEY, '1');
        } catch {
            // Private browsing — just hide it for this session.
        }

        setInstallEvent(null);
    };

    const install = async () => {
        if (!installEvent) {
            return;
        }

        await installEvent.prompt();
        await installEvent.userChoice;
        setInstallEvent(null);
    };

    if (!installEvent) {
        return null;
    }

    return (
        <div className="fixed inset-x-4 bottom-4 z-50 mx-auto max-w-sm sm:right-4 sm:left-auto sm:mx-0">
            <div className="border-border bg-card flex items-start gap-3 rounded-2xl border p-4 shadow-lg">
                <span className="bg-teal text-cream flex size-10 shrink-0 items-center justify-center rounded-xl">
                    <Download className="size-5" />
                </span>
                <div className="flex min-w-0 flex-1 flex-col gap-2">
                    <div className="flex flex-col gap-0.5">
                        <p className="text-sm font-semibold">{title}</p>
                        <p className="text-muted-foreground text-xs">
                            {description}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button size="sm" variant="brand" onClick={install}>
                            Install
                        </Button>
                        <Button size="sm" variant="ghost" onClick={dismiss}>
                            Not now
                        </Button>
                    </div>
                </div>
                <button
                    type="button"
                    onClick={dismiss}
                    aria-label="Dismiss"
                    className="text-muted-foreground hover:text-foreground -mt-1 -mr-1 shrink-0 rounded-md p-1 transition-colors"
                >
                    <X className="size-4" />
                </button>
            </div>
        </div>
    );
}
