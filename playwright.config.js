import { defineConfig, devices } from '@playwright/test';
import { readFileSync } from 'node:fs';

/**
 * Node doesn't auto-load Laravel's own .env the way `php artisan` does —
 * without this, APP_URL below silently falls back to localhost, which
 * shares no session/CSRF cookies with whatever real host the app is
 * actually reachable at (e.g. a LAN IP), so login would submit against
 * one origin using a CSRF token issued for another and fail with a 419.
 * A tiny manual read, not the `dotenv` package — this only ever needs
 * the one APP_URL line, not full .env parsing/expansion semantics.
 */
function readAppUrlFromDotenv() {
    try {
        const contents = readFileSync(new URL('.env', import.meta.url), 'utf-8');
        const match = contents.match(/^APP_URL=(.*)$/m);
        return match ? match[1].trim() : null;
    } catch {
        return null;
    }
}

/**
 * Real-browser test config (tests/browser/) — run via `npm run test:e2e`,
 * entirely separate from the PHP suite (`php artisan test`). Covers
 * client-side JS/live-DOM behavior a request/response Feature test can't
 * reach at all (see README's testing section for why this exists).
 *
 * Chosen over Laravel Dusk specifically because Playwright downloads and
 * manages its OWN pinned browser build (via `npx playwright install`),
 * independent of whatever Chrome happens to be installed on the host —
 * Dusk's ChromeDriver has to be version-matched to the system's Chrome by
 * hand, which breaks silently whenever that Chrome auto-updates.
 *
 * Talks to APP_URL (an already-running instance of the app — same
 * requirement Dusk had), not a server this config starts itself: tests
 * exercise real HTTP requests against the real dev database, so there's
 * no in-process transaction to roll back — each test creates and tears
 * down its own disposable fixtures instead (see tests/browser/support.js).
 */
export default defineConfig({
    testDir: './tests/browser',
    fullyParallel: false, // fixtures are hand-managed per test, not isolated by a DB transaction
    forbidOnly: !!process.env.CI,
    retries: 0,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: process.env.APP_URL || readAppUrlFromDotenv() || 'http://localhost:8000',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
