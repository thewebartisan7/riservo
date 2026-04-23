<?php

namespace App\Http\Controllers\Auth;

use App\Enums\BusinessMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Business;
use App\Models\User;
use App\Services\SlugService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/register');
    }

    public function store(RegisterRequest $request, SlugService $slugService): RedirectResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
        ]);

        // Codex Round-10 (D-143): populate `country` at signup so the
        // Connected Account onboarding gate (D-141) has a value to check.
        // A null/unsupported country refuses Stripe Express onboarding —
        // post-migration signups would otherwise be permanently locked out.
        // MVP pre-launch is Switzerland-only; the default tracks
        // `config('payments.default_onboarding_country')` so extending to
        // another country is a config flip. A future business-onboarding
        // step (BACKLOG: "Collect country during business onboarding") will
        // let the admin override this per-business before they ever reach
        // Stripe.
        $business = Business::create([
            'name' => $request->validated('business_name'),
            'slug' => $slugService->generateUniqueSlug($request->validated('business_name')),
            'country' => config('payments.default_onboarding_country'),
        ]);

        $business->attachOrRestoreMember($user, BusinessMemberRole::Admin);

        Auth::login($user);

        $request->session()->put('current_business_id', $business->id);

        $user->sendEmailVerificationNotification();

        return redirect()->route('verification.notice');
    }
}
