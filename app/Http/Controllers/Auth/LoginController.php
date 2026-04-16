<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\BusinessMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/login', [
            'status' => session('status'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $this->pinCurrentBusiness($request);

        return redirect()->intended($this->redirectPath($request));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function redirectPath(Request $request): string
    {
        $user = $request->user();

        if ($user && $user->hasBusinessRole('admin', 'staff')) {
            return route('dashboard');
        }

        if ($user && $user->isCustomer()) {
            return route('customer.bookings');
        }

        return route('dashboard');
    }

    /**
     * Pin the user's oldest active membership into the session so the very first request
     * after login lands on a known tenant. The ResolveTenantContext middleware would
     * self-heal to the same value on the next request, but pinning here avoids a
     * "no tenant yet" window on the redirect itself.
     */
    private function pinCurrentBusiness(Request $request): void
    {
        $user = $request->user();

        if (! $user) {
            return;
        }

        $membership = BusinessMember::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($membership === null) {
            return;
        }

        $request->session()->put('current_business_id', $membership->business_id);
    }
}
