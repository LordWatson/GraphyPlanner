import { useEffect, useState } from 'react';
import { toast } from 'sonner';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Step M-2.8 — the vendor's hosted connect page, opened in a popup so the current page stays put.
 * `connect(id, url)` POSTs to the given endpoint for a connect URL, sends the popup there, and
 * calls `onDone` when the callback page confirms (`postMessage`, see
 * `resources/views/social-accounts/connected.blade.php`) or the popup closes. Shared by the staff
 * Accounts tab, the public self-connect page and the client portal.
 */
export function useConnectPopup(onDone: () => void): {
    connectingId: number | null;
    connect: (id: number, endpoint: string) => Promise<void>;
} {
    const [connectingId, setConnectingId] = useState<number | null>(null);

    useEffect(() => {
        const handleMessage = (event: MessageEvent) => {
            if (
                event.origin === window.location.origin &&
                event.data?.type === 'graphy:social-account-connected'
            ) {
                onDone();
            }
        };

        window.addEventListener('message', handleMessage);

        return () => window.removeEventListener('message', handleMessage);
    }, [onDone]);

    const connect = async (id: number, endpoint: string) => {
        setConnectingId(id);
        const popup = window.open('about:blank', `graphy-connect-${id}`);

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok || !data.url) {
                popup?.close();
                toast.error(
                    typeof data.message === 'string'
                        ? data.message
                        : 'We couldn’t start the connection. Please try again.',
                );

                return;
            }

            if (popup) {
                popup.location.href = data.url;
                const interval = window.setInterval(() => {
                    if (popup.closed) {
                        window.clearInterval(interval);
                        onDone();
                    }
                }, 1000);
            } else {
                // Popup blocked — fall back to a plain new tab.
                window.open(data.url, '_blank');
            }
        } catch {
            popup?.close();
            toast.error('We couldn’t start the connection. Please try again.');
        } finally {
            setConnectingId(null);
        }
    };

    return { connectingId, connect };
}
