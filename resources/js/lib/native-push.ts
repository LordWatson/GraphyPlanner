import { Capacitor } from '@capacitor/core';
import { PushNotifications } from '@capacitor/push-notifications';
import { http } from '@/lib/http';
import { destroy, store } from '@/routes/push/devices';

/**
 * Native shell (Capacitor) — the phone's push opt-in. The browser's service-worker subscription
 * doesn't exist inside a native WebView, so the same toggle (`usePush`) registers the device's FCM
 * token here instead. Loaded only inside the shell.
 *
 * What this phone opted into is remembered per user in localStorage, so a second person signing in
 * on the same phone is never subscribed without asking.
 */

const KEY = 'alima.native-push';

type Record = { user: number; token: string | null };

function read(): Record | null {
    try {
        return JSON.parse(localStorage.getItem(KEY) ?? 'null') as Record | null;
    } catch {
        return null;
    }
}

function write(record: Record | null): void {
    try {
        if (record) {
            localStorage.setItem(KEY, JSON.stringify(record));
        } else {
            localStorage.removeItem(KEY);
        }
    } catch {
        // Storage unavailable — the opt-in just won't survive a restart.
    }
}

let listening: Promise<unknown> | null = null;
let pending: ((ok: boolean) => void) | null = null;

/** One permanent listener: every token the OS hands us (first, refreshed, rotated) is stored. */
function listen(): Promise<unknown> {
    listening ??= Promise.all([
        PushNotifications.addListener('registration', async ({ value }) => {
            const record = read();

            if (!record) {
                return;
            }

            const result = await http.post(store().url, {
                token: value,
                platform: Capacitor.getPlatform(),
            });

            if (result.ok) {
                write({ ...record, token: value });
            }

            pending?.(result.ok);
            pending = null;
        }),
        PushNotifications.addListener('registrationError', () => {
            pending?.(false);
            pending = null;
        }),
    ]);

    return listening;
}

async function register(): Promise<boolean> {
    await listen();
    const done = new Promise<boolean>((resolve) => (pending = resolve));
    await PushNotifications.register();

    return done;
}

export function isOptedIn(userId: number): boolean {
    const record = read();

    return record?.user === userId && record.token !== null;
}

export async function isBlocked(): Promise<boolean> {
    return (await PushNotifications.checkPermissions()).receive === 'denied';
}

/** The toggle was turned on: ask for permission now (never at launch), then register. */
export async function enable(
    userId: number,
): Promise<'subscribed' | 'blocked' | 'failed'> {
    const { receive } = await PushNotifications.requestPermissions();

    if (receive !== 'granted') {
        return receive === 'denied' ? 'blocked' : 'failed';
    }

    write({ user: userId, token: null });

    if (await register()) {
        return 'subscribed';
    }

    write(null);

    return 'failed';
}

export async function disable(): Promise<void> {
    const token = read()?.token;

    if (token) {
        await http.delete(destroy().url, { token });
    }

    await PushNotifications.unregister().catch(() => {});
    write(null);
}

/**
 * App start / sign-in: re-register a phone this user already opted in on, so the server has the
 * current token (it rotates) and a fresh "last seen". Never prompts.
 */
export async function refresh(userId: number): Promise<void> {
    if (read()?.user !== userId) {
        return;
    }

    if ((await PushNotifications.checkPermissions()).receive === 'granted') {
        await register();
    }
}
