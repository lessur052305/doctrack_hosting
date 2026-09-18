import { test, expect } from '@playwright/test';
import { createCountdownGuardFixture, cleanupCountdownGuardFixture } from './support.js';

/**
 * Real-browser regression coverage for the minimum-review-time countdown
 * bug: closing "Review & Comment" (or "View original file") early used to
 * leave the countdown ticking in the background — either because
 * startReviewCountdown() itself kept running, or because the queue's own
 * live-refresh blindly restarted a fresh countdown on the next poll/
 * broadcast with no idea the popup had actually closed — letting
 * Approve/Reject "unlock" well before the real minimum review time was
 * ever spent. See approver/dashboard.blade.php's openReviewDocumentCounts
 * guard, which is what this test actually exercises.
 */

test.describe('review countdown guard', () => {
    let fixture;

    test.beforeEach(() => {
        fixture = createCountdownGuardFixture();
    });

    test.afterEach(() => {
        cleanupCountdownGuardFixture();
    });

    test('closing the review popup early keeps Approve/Reject locked even after the original countdown window would have passed', async ({ page }) => {
        await page.goto('/login');
        await page.fill('input[name="email"]', fixture.approverEmail);
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');
        await page.waitForURL('**/approver/dashboard');

        await page.click('text=Review & Comment');
        await page.waitForSelector('#kpi-drilldown-overlay:not(.hidden)');
        await page.waitForTimeout(6000); // stay open for 6 real seconds — under the 10s minimum

        await page.click('#kpi-drilldown-overlay button[aria-label="Close"]');
        await page.waitForTimeout(6000); // wait past the ORIGINAL 10s mark since opening (6s + 6s = 12s)

        const approveBtn = page.locator('.review-decide-btn.from-approved-500');
        await expect(approveBtn).toBeDisabled();
    });

    test('staying in the review popup long enough for the real minimum review time correctly unlocks the buttons', async ({ page }) => {
        await page.goto('/login');
        await page.fill('input[name="email"]', fixture.approverEmail);
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');
        await page.waitForURL('**/approver/dashboard');

        await page.click('text=Review & Comment');
        await page.waitForSelector('#kpi-drilldown-overlay:not(.hidden)');
        await page.waitForTimeout(11000); // past config('review.min_review_seconds', 10)

        const approveBtn = page.locator('.review-decide-btn.from-approved-500');
        await expect(approveBtn).toBeEnabled();
    });
});
