<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $business = $user->currentBusiness();

        if (! $business) {
            return $next($request);
        }

        if (! $user->hasBusinessRole('admin')) {
            return $next($request);
        }

        if (! $business->isOnboarded()) {
            return redirect()->route('onboarding.show', ['step' => $business->onboarding_step]);
        }

        return $next($request);
    }
}
