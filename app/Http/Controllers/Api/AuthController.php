<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Token-based auth for API clients (mobile apps, external integrations) —
 * separate from web/AuthController, which authenticates browser sessions
 * via cookies. Same account/credentials, different transport: a client
 * calls POST /api/v1/login once, gets back a bearer token, and sends it
 * as `Authorization: Bearer <token>` on every request after that instead
 * of carrying a session cookie.
 */
class AuthController extends Controller
{
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOGIN_DECAY_SECONDS = 60;

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        // Same per-username+IP throttle as the web login (AuthController),
        // kept independent of it — a locked-out web session shouldn't also
        // lock out this account's API access, and vice versa, since they're
        // different attack surfaces with their own rate limit keys.
        $throttleKey = 'api|' . Str::lower($credentials['username']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            throw ValidationException::withMessages([
                'username' => ["Too many login attempts. Try again in {$seconds} second(s)."],
            ]);
        }

        $user = User::where('username', $credentials['username'])->first();

        if (!$user || !$user->is_active || !Auth::validate(['username' => $credentials['username'], 'password' => $credentials['password']])) {
            $hits = RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);
            if ($hits >= self::MAX_LOGIN_ATTEMPTS) {
                Cache::put(
                    $throttleKey . ':timer',
                    now()->addSeconds(self::LOGIN_DECAY_SECONDS)->getTimestamp(),
                    self::LOGIN_DECAY_SECONDS
                );
            }

            throw ValidationException::withMessages(['username' => ['Invalid credentials.']]);
        }

        RateLimiter::clear($throttleKey);

        $token = $user->createToken($credentials['device_name'])->plainTextToken;

        AuditLog::record($user->user_id, null, 'api_login', "User {$user->username} authenticated via API (device: {$credentials['device_name']}).");

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $tokenName = $user->currentAccessToken()->name;
        $user->currentAccessToken()->delete();

        AuditLog::record($user->user_id, null, 'api_logout', "User {$user->username} revoked their API token (device: {$tokenName}).");

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        return new UserResource($request->user());
    }
}
