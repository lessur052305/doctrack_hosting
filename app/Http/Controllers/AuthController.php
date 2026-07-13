<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
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

        // Eloquent/Auth facade builds a parameterized query internally — no
        // raw SQL string interpolation of user input anywhere in this app.
        if (Auth::attempt(['username' => $credentials['username'], 'password' => $credentials['password']])) {
            $request->session()->regenerate();

            $user = Auth::user();
            AuditLog::record($user->user_id, null, 'login', "User {$user->username} logged in.");

            return redirect()->intended($this->dashboardRouteFor($user->role));
        }

        return back()->withErrors(['username' => 'Invalid credentials.'])->onlyInput('username');
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
