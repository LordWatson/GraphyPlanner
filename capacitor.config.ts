import type { CapacitorConfig } from '@capacitor/cli';

/**
 * The native shell (iOS + Android). Graphy is a server-driven Inertia app, so nothing is bundled:
 * the WebView loads the hosted app from `server.url` (remote-URL mode) and the only local page is
 * the offline fallback in `native/www` (built by `scripts/native-www.mjs`).
 *
 * `CAPACITOR_SERVER_URL` picks the app the shell opens: the production URL by default, your LAN
 * URL for a dev build (e.g. `http://192.168.1.20:8000`). It is baked in at `cap sync` time.
 *
 * The projects live in `native/`, not `android/` — that folder is the Play TWA (Step GTM-6.1).
 */
const serverUrl = process.env.CAPACITOR_SERVER_URL || 'https://app.alima.com';

const config: CapacitorConfig = {
    appId: 'com.alima.app',
    appName: 'Alima',
    webDir: 'native/www',
    backgroundColor: '#F5F2E8',
    android: { path: 'native/android' },
    ios: { path: 'native/ios' },
    server: {
        url: serverUrl,
        cleartext: serverUrl.startsWith('http://'),
        // Shown when the remote app can't be reached (index.html is the offline page).
        errorPath: 'index.html',
    },
    plugins: {
        PushNotifications: { presentationOptions: ['badge', 'sound', 'alert'] },
    },
};

export default config;
