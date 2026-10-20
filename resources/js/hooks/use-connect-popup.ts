import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { http } from '@/lib/http';

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

        const result = await http.post<{ url?: string }>(endpoint);
        setConnectingId(null);

        if (!result.ok || !result.data.url) {
            popup?.close();
            toast.error(
                result.ok || result.status === 0
                    ? 'We couldn’t start the connection. Please try again.'
                    : result.message,
            );

            return;
        }

        const url = result.data.url;

        if (popup) {
            popup.location.href = url;
            const interval = window.setInterval(() => {
                if (popup.closed) {
                    window.clearInterval(interval);
                    onDone();
                }
            }, 1000);
        } else {
            // Popup blocked — fall back to a plain new tab.
            window.open(url, '_blank');
        }
    };

    return { connectingId, connect };
}
