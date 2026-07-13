<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strict Role-Based Access Control middleware.
 *
 * Usage in routes: ->middleware('role:admin') or ->middleware('role:admin,approver')
 *
 * This enforces the Role-Based Access Control (RBAC) requirement in
 * Operational Definition of Terms (1.6) and Section 3's "Enforce strict
 * RBAC middleware" instruction. Every protected route explicitly declares
 * which of the three roles (Admin, Staff-Originator, Staff-Approver) may
 * reach it; there is no implicit/default-allow path.
 */
class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user || !$user->is_active) {
            abort(403, 'Account inactive or not authenticated.');
        }

        if (!in_array($user->role, $roles, true)) {
            abort(403, 'You do not have permission to access this resource.');
        }

        return $next($request);
    }
}
