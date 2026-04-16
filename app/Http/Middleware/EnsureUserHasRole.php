<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * Authorises against the CURRENTLY-RESOLVED tenant, not "any business the user is
     * a member of". A user who is admin in Business A and staff in Business B must
     * pin to A to pass `role:admin`, otherwise 403. The customer branch is unchanged
     * — customer role is global, not tenant-scoped.
     *
     * @param  string  ...$roles  Allowed roles: 'admin', 'staff', 'customer'
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        $tenant = tenant();
        $activeRole = $tenant->has() ? $tenant->role()?->value : null;

        foreach ($roles as $role) {
            if ($role === 'customer') {
                if ($user->isCustomer()) {
                    return $next($request);
                }

                continue;
            }

            if ($activeRole === $role) {
                return $next($request);
            }
        }

        abort(403);
    }
}
