<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Populates the per-request TenantContext from the session + the authenticated user.
 *
 * Runs after `auth` on the `web` group. No-op for unauthenticated requests and for users
 * with no active business memberships (customers, guest-customers logging in via magic link).
 *
 * Resolution rules:
 *  1. If session('current_business_id') is set AND the user has an active membership in
 *     that business, use it.
 *  2. Otherwise, fall back to the user's oldest active membership (ordered by
 *     business_members.created_at ASC, then id ASC — deterministic even under ties).
 *  3. Persist the chosen business id back to the session so the next request is a
 *     single-lookup hit and so React's shared props stay stable across page visits.
 *
 * Self-healing: a stale session value (deleted / foreign membership) falls through rule 1
 * into rule 2 and is rewritten in step 3. Users never see their session "stuck" on an
 * invalid pin — which also means adding a business-switcher UX (R-2B) is purely additive:
 * it writes a new id into session and the next request picks it up.
 */
class ResolveTenantContext
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $membership = $this->resolveMembership($user->id, $request->session()->get('current_business_id'));

        if ($membership === null) {
            return $next($request);
        }

        $business = Business::find($membership->business_id);

        if ($business === null) {
            return $next($request);
        }

        $this->tenant->set($business, $membership->role);

        if ($request->session()->get('current_business_id') !== $business->id) {
            $request->session()->put('current_business_id', $business->id);
        }

        return $next($request);
    }

    private function resolveMembership(int $userId, mixed $pinnedBusinessId): ?BusinessMember
    {
        if (is_int($pinnedBusinessId) || (is_string($pinnedBusinessId) && ctype_digit($pinnedBusinessId))) {
            $pinned = BusinessMember::query()
                ->where('user_id', $userId)
                ->where('business_id', (int) $pinnedBusinessId)
                ->first();

            if ($pinned !== null) {
                return $pinned;
            }
        }

        return BusinessMember::query()
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }
}
