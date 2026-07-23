<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Regression coverage for #6 (no rate limiting on login or uploads). Both
 * are backed by Laravel's cache-based RateLimiter, so state must be
 * cleared between tests or one test's attempts bleed into the next.
 */
beforeEach(function () {
    RateLimiter::clear('originator@example.test|127.0.0.1');
    RateLimiter::clear('admin@example.test|127.0.0.1');
});

it('blocks login after 5 failed attempts for the same email+IP', function () {
    User::factory()->originator()->create(['email' => 'originator@example.test', 'password_hash' => bcrypt('correct-password')]);

    for ($i = 0; $i < 5; $i++) {
        $this->post(route('login.attempt'), ['email' => 'originator@example.test', 'password' => 'wrong-password']);
    }

    $response = $this->post(route('login.attempt'), ['email' => 'originator@example.test', 'password' => 'wrong-password']);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->get('email')[0])->toContain('Too many login attempts');
});

it('does not block a login attempt for a different email after another one is throttled', function () {
    User::factory()->originator()->create(['email' => 'originator@example.test', 'password_hash' => bcrypt('correct-password')]);
    User::factory()->admin()->create(['email' => 'admin@example.test', 'password_hash' => bcrypt('correct-password')]);

    for ($i = 0; $i < 6; $i++) {
        $this->post(route('login.attempt'), ['email' => 'originator@example.test', 'password' => 'wrong-password']);
    }

    $response = $this->post(route('login.attempt'), ['email' => 'admin@example.test', 'password' => 'correct-password']);

    $response->assertRedirect(route('admin.dashboard'));
});

it('clears the throttle counter on a successful login', function () {
    User::factory()->originator()->create(['email' => 'originator@example.test', 'password_hash' => bcrypt('correct-password')]);

    for ($i = 0; $i < 3; $i++) {
        $this->post(route('login.attempt'), ['email' => 'originator@example.test', 'password' => 'wrong-password']);
    }

    $this->post(route('login.attempt'), ['email' => 'originator@example.test', 'password' => 'correct-password'])
        ->assertRedirect(route('originator.dashboard'));

    auth()->logout();

    // 3 more failures shouldn't hit the 5-attempt cap since the counter reset on success
    for ($i = 0; $i < 3; $i++) {
        $this->post(route('login.attempt'), ['email' => 'originator@example.test', 'password' => 'wrong-password']);
    }
    $response = $this->post(route('login.attempt'), ['email' => 'originator@example.test', 'password' => 'wrong-password']);

    $response->assertSessionHasErrors(['email' => 'Invalid credentials. 1 attempt(s) remaining before temporary lockout.']);
});

it('tells the user how many attempts remain before lockout', function () {
    User::factory()->originator()->create(['email' => 'originator@example.test', 'password_hash' => bcrypt('correct-password')]);

    $response = $this->post(route('login.attempt'), ['email' => 'originator@example.test', 'password' => 'wrong-password']);

    $response->assertSessionHasErrors(['email' => 'Invalid credentials. 4 attempt(s) remaining before temporary lockout.']);
});

it('warns distinctly on the last attempt before lockout, instead of a bare generic message', function () {
    User::factory()->originator()->create(['email' => 'originator@example.test', 'password_hash' => bcrypt('correct-password')]);

    for ($i = 0; $i < 4; $i++) {
        $this->post(route('login.attempt'), ['email' => 'originator@example.test', 'password' => 'wrong-password']);
    }
    $response = $this->post(route('login.attempt'), ['email' => 'originator@example.test', 'password' => 'wrong-password']);

    $response->assertSessionHasErrors([
        'email' => 'Invalid credentials. This was your last attempt — the next failure will trigger a temporary lockout.',
    ]);
});

it('shows the full 60-second countdown at lockout even when getting there took a while', function () {
    User::factory()->originator()->create(['email' => 'originator@example.test', 'password_hash' => bcrypt('correct-password')]);

    // Simulate the user taking 8 seconds between each of the first 4 failed
    // attempts (32s total). Without the timer-reset fix, RateLimiter::hit()'s
    // decay clock starts on the FIRST attempt and is never extended by later
    // ones, so by the time the 5th attempt trips the cap, ~32s would already
    // be gone from the 60s window before the countdown is even shown.
    for ($i = 0; $i < 4; $i++) {
        $this->post(route('login.attempt'), ['email' => 'originator@example.test', 'password' => 'wrong-password']);
        $this->travel(8)->seconds();
    }
    $this->post(route('login.attempt'), ['email' => 'originator@example.test', 'password' => 'wrong-password']); // 5th: trips the cap

    // The 6th request — checked immediately after, no further delay — is
    // the one that actually gets blocked and shown the countdown.
    $response = $this->post(route('login.attempt'), ['email' => 'originator@example.test', 'password' => 'wrong-password']);

    $response->assertSessionHas('login_retry_after', 60);
});

it('rate limits document uploads past 20 requests per minute for the same user', function () {
    $originator = User::factory()->originator()->create();

    $response = null;
    for ($i = 0; $i < 21; $i++) {
        $response = $this->actingAs($originator)->post(route('originator.documents.store'), [
            'files' => [UploadedFile::fake()->createWithContent("doc{$i}.txt", 'some content ' . str_repeat('word ', 10))],
            'due_date' => now()->addHour()->format('Y-m-d\TH:i'),
        ]);
    }

    $response->assertStatus(429);
});
