import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { http } from '@/lib/http';
import { subscribe as subscribeRoute } from '@/routes/business/push';
import { unsubscribe as unsubscribeRoute } from '@/routes/business/push';

/**
 * Step B-7.4 — per-browser push opt-in for the business shell. Reads the VAPID key from the shared
 * `pwa` prop, reflects the browser's current subscription state, and stores/removes it server-side
 * through the shared `http` helper. Everything degrades quietly when push is unsupported or
 * unconfigured, so the toggle simply doesn't show.
 */

function urlBase64ToUint8Array(base64String: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding)
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = atob(base64);
    // Allocate over a concrete ArrayBuffer so it satisfies `BufferSource` for applicationServerKey.
    const output = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i++) {
        output[i] = raw.charCodeAt(i);
    }

    return output;
}

export type PushState = {
    /** The browser can do web push and a service worker is available. */
    supported: boolean;
    /** Push is turned on and configured server-side (VAPID key present). */
    configured: boolean;
    /** This browser is currently subscribed. */
    subscribed: boolean;
    /** A subscribe/unsubscribe call is in flight. */
    busy: boolean;
    /** Permission was blocked at the browser level — the toggle can't help. */
    blocked: boolean;
    subscribe: () => Promise<void>;
    unsubscribe: () => Promise<void>;
};

export function usePush(): PushState {
    const { pwa } = usePage().props;
    const publicKey = pwa?.pushPublicKey ?? null;
    const configured = Boolean(pwa?.pushEnabled && publicKey);

    const supported =
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window;

    const [subscribed, setSubscribed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [blocked, setBlocked] = useState(false);

    useEffect(() => {
        if (!supported) {
            return;
        }

        setBlocked(Notification.permission === 'denied');

        let cancelled = false;

        navigator.serviceWorker.ready
            .then((registration) => registration.pushManager.getSubscription())
            .then((subscription) => {
                if (!cancelled) {
                    setSubscribed(subscription !== null);
                }
            })
            .catch(() => {});

        return () => {
            cancelled = true;
        };
    }, [supported]);

    const subscribe = useCallback(async () => {
        if (!supported || !configured || !publicKey || busy) {
            return;
        }

        setBusy(true);

        try {
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                setBlocked(permission === 'denied');
                return;
            }

            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(publicKey),
            });

            const result = await http.post(
                subscribeRoute().url,
                subscription.toJSON(),
                {
                    toastOnError: true,
                },
            );

            if (result.ok) {
                setSubscribed(true);
            } else {
                // Couldn't persist it — don't leave a dangling browser subscription.
                await subscription.unsubscribe().catch(() => {});
            }
        } finally {
            setBusy(false);
        }
    }, [supported, configured, publicKey, busy]);

    const unsubscribe = useCallback(async () => {
        if (!supported || busy) {
            return;
        }

        setBusy(true);

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription =
                await registration.pushManager.getSubscription();

            if (subscription) {
                await http.delete(unsubscribeRoute().url, {
                    endpoint: subscription.endpoint,
                });
                await subscription.unsubscribe().catch(() => {});
            }

            setSubscribed(false);
        } finally {
            setBusy(false);
        }
    }, [supported, busy]);

    return {
        supported,
        configured,
        subscribed,
        busy,
        blocked,
        subscribe,
        unsubscribe,
    };
}
