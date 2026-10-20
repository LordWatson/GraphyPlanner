import { defineConfig, devices } from '@playwright/test';
import { baseURL } from './tests/e2e/support/env.mjs';

/**
 * Step I-14.6 / M-1.5 — end-to-end journeys against the real app (built assets, PHP server,
 * isolated SQLite database; see `tests/e2e/support`). Run with `npm run test:e2e` after
 * `npm run build`. CI runs them on every PR.
 */
export default defineConfig({
    testDir: 'tests/e2e',
    testMatch: '**/*.spec.ts',
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    forbidOnly: Boolean(process.env.CI),
    reporter: process.env.CI ? [['github'], ['list']] : 'list',
    timeout: 120_000,
    expect: { timeout: 10_000 },
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        timezoneId: 'Europe/London',
        locale: 'en-GB',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: {
        command: 'node tests/e2e/support/server.mjs',
        url: `${baseURL}/up`,
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
        stdout: 'ignore',
        stderr: 'pipe',
    },
});
