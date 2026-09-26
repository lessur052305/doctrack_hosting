<?php

namespace App\Http\Controllers;

use App\Services\PasswordBreachCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Backs the live "not a known leaked password" line on the Create Account and
 * Reset Password forms. Done server-side (rather than from the browser) so it
 * works on plain-HTTP addresses — browsers only allow the crypto API the
 * old in-browser check needed on HTTPS/localhost, which silently left the
 * hint grey on a LAN address. The password is already sent to this server at
 * submit; it is neither stored nor logged here.
 */
class PasswordBreachController extends Controller
{
    public function check(Request $request, PasswordBreachCheck $breachCheck): JsonResponse
    {
        $validated = $request->validate(['password' => ['required', 'string', 'max:255']]);

        return response()
            ->json(['status' => $breachCheck->check($validated['password'])])
            ->header('Cache-Control', 'no-store');
    }
}
