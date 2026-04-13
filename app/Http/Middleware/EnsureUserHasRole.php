<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  string  ...$roles  Allowed roles: 'admin', 'collaborator', 'customer'
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        foreach ($roles as $role) {
            if ($role === 'customer') {
                if ($user->isCustomer()) {
                    return $next($request);
                }
            } else {
                if ($user->hasBusinessRole($role)) {
                    return $next($request);
                }
            }
        }

        abort(403);
    }
}
