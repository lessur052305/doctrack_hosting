<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOGIN_DECAY_SECONDS = 60;

    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        // Request validation on every incoming payload (Section 3 requirement).
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        // Keyed by username+IP (the same approach Laravel's own Breeze
        // starter kit uses) rather than IP alone, so one user's mistakes
        // can't lock out everyone else behind the same NAT/office network.
        $throttleKey = Str::lower($credentials['username']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            // login_retry_after is flashed separately from the error string
            // (rather than only embedding the number in the message) so the
            // view can drive a live client-side countdown instead of a
            // number that's already stale by the time the page renders.
            return back()->withErrors([
                'username' => "Too many login attempts. Try again in {$seconds} second(s).",
            ])->with('login_retry_after', $seconds)->onlyInput('username');
        }

        // Eloquent/Auth facade builds a parameterized query internally — no
        // raw SQL string interpolation of user input anywhere in this app.
        if (Auth::attempt(['username' => $credentials['username'], 'password' => $credentials['password']])) {
            RateLimiter::clear($throttleKey);
            $request->session()->regenerate();

            $user = Auth::user();
            AuditLog::record($user->user_id, null, 'login', "User {$user->username} logged in.");

            return redirect()->intended($this->dashboardRouteFor($user->role));
        }

        $hits = RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);

        // RateLimiter::hit() sets its internal decay timer via a cache
        // add() — a no-op once the key already exists — so the timer is
        // only ever established on the FIRST failed attempt in a window,
        // not extended on later ones. Left alone, the lockout duration
        // actually shown once the cap is hit is "60s minus however long
        // the user took typing out all 5 attempts," not a full 60s. The
        // instant this hit is the one that trips the cap, force the timer
        // to a fresh full window starting now, so the countdown the user
        // sees always reads 60, not some already-decayed number.
        if ($hits >= self::MAX_LOGIN_ATTEMPTS) {
            Cache::put(
                $throttleKey . ':timer',
                now()->addSeconds(self::LOGIN_DECAY_SECONDS)->getTimestamp(),
                self::LOGIN_DECAY_SECONDS
            );
        }

        // Always name the remaining count, including the 0 case (this
        // attempt just used the last slot) — falling back to a bare
        // "Invalid credentials." on that one attempt would silently drop
        // the only warning the user gets before the next failure locks
        // them out.
        $remaining = self::MAX_LOGIN_ATTEMPTS - $hits;
        $message = $remaining > 0
            ? "Invalid credentials. {$remaining} attempt(s) remaining before temporary lockout."
            : 'Invalid credentials. This was your last attempt — the next failure will trigger a temporary lockout.';

        return back()->withErrors(['username' => $message])->onlyInput('username');
    }

    public function logout(Request $request)
    {
        $user = Auth::user();
        if ($user) {
            AuditLog::record($user->user_id, null, 'logout', "User {$user->username} logged out.");
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function dashboardRouteFor(string $role): string
    {
        return match ($role) {
            'admin' => route('admin.dashboard'),
            'approver' => route('approver.dashboard'),
            default => route('originator.dashboard'),
        };
    }
}
