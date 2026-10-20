// Step I-14.6 / M-1.5 — Playwright's web server: fresh SQLite database, then PHP's built-in server
// on the app's front controller. (Not `artisan serve`: it drops environment variables that are
// also in `.env`, which would point the journeys at the developer's own database.)
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { appEnv, databasePath, logPath, port, root } from './env.mjs';

const php = process.env.E2E_PHP ?? 'php';
const env = { ...process.env, ...appEnv };

for (const path of [databasePath, logPath]) {
    if (existsSync(path)) {
        rmSync(path);
    }
}

writeFileSync(databasePath, '');

const migrate = spawnSync(
    php,
    ['artisan', 'migrate', '--force', '--no-interaction'],
    {
        cwd: root,
        env,
        stdio: 'inherit',
    },
);

if (migrate.status !== 0) {
    process.exit(migrate.status ?? 1);
}

// Laravel's router script serves from the current directory, so run it from `public/`.
const server = spawn(
    php,
    [
        '-S',
        `127.0.0.1:${port}`,
        join(
            root,
            'vendor',
            'laravel',
            'framework',
            'src',
            'Illuminate',
            'Foundation',
            'resources',
            'server.php',
        ),
    ],
    { cwd: join(root, 'public'), env, stdio: 'inherit' },
);

const stop = () => server.kill();
process.on('SIGINT', stop);
process.on('SIGTERM', stop);
server.on('exit', (code) => process.exit(code ?? 0));
