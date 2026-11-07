// Frontend guard rails run by `npm run check` (and so by CI):
//
// - Step I-14.2 / M-1.4: no raw `fetch(` outside `resources/js/lib/http.ts` — every JSON call
//   goes through the shared `http` helper (CSRF, errors, toasts).
// - Step I-4.6 / M-1.6: no post-editor file over EDITOR_MAX_LINES lines, so the editor stays split
//   into feature components.
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';

const root = join(import.meta.dirname, '..');
const js = join(root, 'resources', 'js');
const EDITOR_MAX_LINES = 300;

const ignoredDirs = new Set(['actions', 'routes', 'wayfinder']);
const failures = [];

function walk(dir) {
    for (const entry of readdirSync(dir)) {
        const path = join(dir, entry);

        if (statSync(path).isDirectory()) {
            if (!ignoredDirs.has(entry)) {
                walk(path);
            }
        } else if (/\.(ts|tsx)$/.test(entry)) {
            check(path);
        }
    }
}

function check(path) {
    const file = relative(root, path).split(sep).join('/');
    const source = readFileSync(path, 'utf8');
    const lines = source.split('\n');

    // Inertia resolves pages with a glob over `pages/**`, so a test file anywhere under there is
    // compiled into the production build as if it were a page. One was: a 425KB chunk of
    // @testing-library, 11% of the deployed JavaScript, that no user could ever load.
    //
    // A `__tests__` subfolder does *not* get you out of it — the glob is `pages/**/*.tsx` and
    // matches that too (I tried). The test has to live outside `pages/`, so page tests go in
    // `resources/js/tests/`.
    if (/^resources\/js\/pages\/.*\.test\.tsx?$/.test(file)) {
        failures.push(
            `${file} is a test under pages/ — the Inertia glob would bundle it into the build as a page. Move it to resources/js/tests/.`,
        );
    }

    if (file !== 'resources/js/lib/http.ts') {
        lines.forEach((line, index) => {
            const code = line.replace(/\/\/.*$/, '').trim();

            if (!code.startsWith('*') && /(^|[^.\w])fetch\(/.test(code)) {
                failures.push(
                    `${file}:${index + 1} raw fetch() — use http from '@/lib/http'.`,
                );
            }
        });
    }

    const isEditor =
        file === 'resources/js/pages/posts/edit.tsx' ||
        file.startsWith('resources/js/components/post-editor/');

    if (isEditor && lines.length > EDITOR_MAX_LINES) {
        failures.push(
            `${file} has ${lines.length} lines (editor files stay under ${EDITOR_MAX_LINES}; split it).`,
        );
    }
}

walk(js);

if (failures.length > 0) {
    console.error(failures.join('\n'));
    process.exit(1);
}

console.log('frontend guards: ok');
