// Step I-14.6 / M-1.5 — the isolated environment the Playwright journeys run the app in: its own
// SQLite database and log file, mail written to the log (so a journey can follow the email
// verification link), and the queue left on the database driver so nothing is ever sent to a
// publishing vendor. Real environment variables win over `.env`, so a developer's local `.env`
// (MySQL, Redis, real keys) is never touched.
import { join } from 'node:path';

export const root = join(import.meta.dirname, '..', '..', '..');
export const port = Number(process.env.E2E_PORT ?? 8123);
export const baseURL = `http://127.0.0.1:${port}`;
export const databasePath = join(root, 'database', 'e2e.sqlite');
export const logPath = join(root, 'storage', 'logs', 'e2e.log');

export const appEnv = {
    APP_ENV: 'local',
    APP_DEBUG: 'true',
    APP_URL: baseURL,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: databasePath,
    DB_URL: '',
    QUEUE_CONNECTION: 'database',
    CACHE_STORE: 'file',
    SESSION_DRIVER: 'file',
    SESSION_SECURE_COOKIE: 'false',
    MAIL_MAILER: 'log',
    LOG_CHANNEL: 'json',
    LOG_JSON_PATH: logPath,
    LOG_LEVEL: 'debug',
    BROADCAST_CONNECTION: 'null',
    PULSE_ENABLED: 'false',
    SENTRY_LARAVEL_DSN: '',
    OPS_ALERT_MAIL_TO: '',
    AI_ENABLED: 'false',
    DEEPSEEK_API_KEY: '',
    AI_IMAGE_PROVIDER: 'null',
    UPLOAD_VIRUS_SCANNER: 'null',
};
