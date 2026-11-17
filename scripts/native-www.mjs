// Builds `native/www` — the only local page in the Capacitor shell — from the PWA's offline page,
// so there is one offline design to maintain. "Try again" goes back to the hosted app instead of
// reloading the fallback itself.
import { copyFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';

const url = process.env.CAPACITOR_SERVER_URL || 'https://app.alima.com';
const html = readFileSync('public/offline.html', 'utf8')
    .replace(
        'location.reload()',
        `location.replace(${JSON.stringify(url).replaceAll('"', "'")})`,
    )
    .replace('/icons/icon-192.png', 'icon-192.png');

if (!html.includes('location.replace(') || !html.includes('"icon-192.png"')) {
    throw new Error(
        'public/offline.html changed shape — update scripts/native-www.mjs',
    );
}

mkdirSync('native/www', { recursive: true });
writeFileSync('native/www/index.html', html);
copyFileSync('public/icons/icon-192.png', 'native/www/icon-192.png');
console.log(`native/www built for ${url}`);
