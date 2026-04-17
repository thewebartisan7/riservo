<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate every mutating dashboard verb behind the active subscription state
 * (D-090). Read paths (GET / HEAD / OPTIONS) pass through unconditionally so
 * a read-only business can still see its data and find the Resubscribe CTA.
 *
 * The billing controller routes live OUTSIDE this gate so a lapsed admin can
 * always reach Subscribe / Portal / Cancel / Resume. Webhooks and public
 * booking are also outside.
 */
class EnsureBusinessCanWrite
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $business = tenant()->business();

        if ($business === null || $business->canWrite()) {
            return $next($request);
        }

        return redirect()
            ->route('settings.billing')
            ->with('error', __('Your subscription has ended. Please resubscribe to continue.'));
    }
}
