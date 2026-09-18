<?php

// Real-browser tests (tests/browser/) run separately via Playwright, not
// through Pest/PHPUnit at all — see package.json's "test:e2e" script and
// the README's testing section. Nothing to register here for those.

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // The 'array' cache driver (phpunit.xml) lives in memory for the
        // whole PHP process, unlike the database — RefreshDatabase resets
        // that between every test, but NOT the cache. Without this, a
        // heartbeat timestamp left behind by one test (possibly under a
        // completely different Carbon::setTestNow()/travelTo() "now")
        // would leak into the next test's first authenticated request —
        // see App\Http\Middleware\CheckForSlaOutage — and could trigger a
        // spurious "outage" purely from cross-test cache pollution, not
        // anything the test itself set up.
        \Illuminate\Support\Facades\Cache::forget('sla_heartbeat_last_seen');
    })
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Backdates a closed DocumentReviewSession so a test can decide/confirm/
 * reject a document without tripping the minimum-review-time gate (see
 * config('review.min_review_seconds') and DocumentReviewSession::
 * secondsSpentSoFar()) — every decision endpoint checks this now, so any
 * test exercising one needs a real review session behind it, the same way
 * a real user would have opened the document viewer first.
 */
function seedReviewTime(\App\Models\User $user, \App\Models\DocumentRepository $document, int $seconds = 15): void
{
    \App\Models\DocumentReviewSession::create([
        'document_id' => $document->document_id,
        'user_id' => $user->user_id,
        'opened_at' => now()->subSeconds($seconds),
        'closed_at' => now(),
        'duration_seconds' => $seconds,
        'session_type' => 'initial',
    ]);
}
