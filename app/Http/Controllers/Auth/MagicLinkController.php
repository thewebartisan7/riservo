<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendMagicLinkRequest;
use App\Models\BusinessMember;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MagicLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/magic-link', [
            'status' => session('status'),
        ]);
    }

    public function store(SendMagicLinkRequest $request): RedirectResponse
    {
        $request->ensureIsNotRateLimited();

        $email = $request->input('email');
        $user = $this->resolveUser($email);

        if ($user) {
            $token = Str::random(64);
            $user->forceFill(['magic_link_token' => $token])->save();

            $url = URL::temporarySignedRoute(
                'magic-link.verify',
                now()->addMinutes(15),
                ['user' => $user->id, 'token' => $token],
            );

            dispatch(function () use ($user, $url) {
                $user->notify(new MagicLinkNotification($url));
            })->afterResponse();
        }

        // Always return success to prevent email enumeration
        return back()->with('status', __('If an account exists for that email, we sent you a login link.'));
    }

    public function verify(Request $request, User $user): RedirectResponse
    {
        $token = $request->query('token');

        if (! $token || $user->magic_link_token === null || $user->magic_link_token !== $token) {
            abort(403, __('This magic link is invalid or has already been used.'));
        }

        $user->forceFill(['magic_link_token' => null])->save();

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        $membership = BusinessMember::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($membership !== null) {
            $request->session()->put('current_business_id', $membership->business_id);
        }

        if ($user->hasBusinessRole('admin', 'staff')) {
            return redirect()->route('dashboard');
        }

        return redirect()->route('customer.bookings');
    }

    /**
     * Resolve or create a User for the given email.
     * For customers without a User record, auto-create one (D-037).
     */
    private function resolveUser(string $email): ?User
    {
        // Check if a User exists with this email
        $user = User::where('email', $email)->first();

        if ($user) {
            return $user;
        }

        // Check if a Customer exists — auto-create User if needed
        $customer = Customer::where('email', $email)->first();

        if (! $customer) {
            return null;
        }

        if ($customer->user_id) {
            return User::find($customer->user_id);
        }

        // Auto-create User for guest customer
        $user = User::create([
            'name' => $customer->name,
            'email' => $customer->email,
            'password' => null,
        ]);

        $customer->update(['user_id' => $user->id]);

        return $user;
    }
}
