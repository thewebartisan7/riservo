<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangeEmailRequest;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): Response|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard'));
        }

        return Inertia::render('auth/verify-email', [
            'status' => session('status'),
            'currentEmail' => $request->user()->email,
        ]);
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->intended(route('dashboard'))->with('success', __('Email verified successfully.'));
    }

    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard'));
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', __('A new verification link has been sent to your email address.'));
    }

    /**
     * Typo-recovery path for D-097: a user who committed a wrong email on the
     * Account page (or at registration) is locked behind `verified` middleware
     * with the broken address, so settings.account is unreachable. This endpoint
     * is auth-only (not verified-required) and lets the user re-enter the
     * correct email, dispatch a fresh verification link, and stay on the notice
     * page.
     */
    public function changeEmail(ChangeEmailRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'email' => $request->validated('email'),
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();

        return back()->with('status', __('We sent a verification link to :email.', ['email' => $user->email]));
    }
}
