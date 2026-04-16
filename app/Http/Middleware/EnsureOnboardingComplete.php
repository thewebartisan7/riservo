<?php

namespace App\Http\Middleware;

use App\Enums\BusinessMemberRole;
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

        $tenant = tenant();

        if (! $tenant->has()) {
            return $next($request);
        }

        if ($tenant->role() !== BusinessMemberRole::Admin) {
            return $next($request);
        }

        $business = $tenant->business();

        if (! $business->isOnboarded()) {
            return redirect()->route('onboarding.show', ['step' => $business->onboarding_step]);
        }

        return $next($request);
    }
}
