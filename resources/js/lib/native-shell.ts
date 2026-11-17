import { Capacitor } from '@capacitor/core';
import { router } from '@inertiajs/react';

/**
 * Native shell (Capacitor) — everything the app does differently when it runs inside the iOS /
 * Android wrapper instead of a browser. A no-op on the web, and the plugin code is only fetched
 * inside the shell.
 *
 * Not handled here because the platform already does it: the Android back button walks history
 * (`@capacitor/app`), links to other hosts open in the system browser, and safe areas are kept
 * clear by the WebView (the page never opts into `viewport-fit=cover`).
 */

export function isNative(): boolean {
    return Capacitor.isNativePlatform();
}

/** Open a link the OS handed us (a tapped notification, an app link) — this origin only. */
function open(url: unknown): void {
    if (typeof url !== 'string' || url === '') {
        return;
    }

    try {
        const target = new URL(url, window.location.origin);

        if (target.origin === window.location.origin) {
            window.location.assign(target.href);
        }
    } catch {
        // Not a URL — ignore it.
    }
}

/** Call before the Inertia app boots, so the first page's `navigate` is seen. */
export function initNativeShell(): void {
    if (!isNative()) {
        return;
    }

    void import('@/lib/native-media').then((media) =>
        media.installNativeFilePicker(),
    );

    void import('@capacitor/app').then(({ App }) =>
        App.addListener('appUrlOpen', ({ url }) => open(url)),
    );

    void import('@capacitor/push-notifications').then(({ PushNotifications }) =>
        PushNotifications.addListener(
            'pushNotificationActionPerformed',
            ({ notification }) => open(notification.data?.url),
        ),
    );

    // Keep the server's device token current for a user who already opted in on this phone.
    let refreshedFor: number | null = null;

    router.on('navigate', (event) => {
        const id = event.detail.page.props.auth?.user?.id ?? null;

        if (typeof id !== 'number' || id === refreshedFor) {
            return;
        }

        refreshedFor = id;
        void import('@/lib/native-push').then((push) => push.refresh(id));
    });
}
