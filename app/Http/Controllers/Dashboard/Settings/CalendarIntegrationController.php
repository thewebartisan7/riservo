<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Http\Controllers\Controller;
use App\Models\CalendarIntegration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

class CalendarIntegrationController extends Controller
{
    /**
     * OAuth scopes requested from Google on connect.
     *
     * @var array<int, string>
     */
    private const GOOGLE_SCOPES = [
        'openid',
        'email',
        'https://www.googleapis.com/auth/calendar.events',
    ];

    public function index(Request $request): Response
    {
        $integration = $request->user()->calendarIntegration;

        return Inertia::render('dashboard/settings/calendar-integration', [
            'connected' => $integration !== null,
            'googleAccountEmail' => $integration?->google_account_email,
            // Reserved slot for Session 2's sync-error surface. Always null today.
            'error' => null,
        ]);
    }

    /**
     * Kick off the Google OAuth consent flow.
     *
     * Inertia form submissions arrive as XHR; Socialite's external 302 cannot
     * cause a browser navigation from XHR, so when the request is an Inertia
     * visit we return `Inertia::location(...)` which instructs the client to
     * perform a full `window.location` change. Non-Inertia POSTs (e.g. a
     * plain HTML form, direct curl) get the raw `RedirectResponse` so they
     * follow the 302 natively.
     */
    public function connect(Request $request): HttpResponse
    {
        // access_type=offline + prompt=consent guarantee Google returns a
        // refresh_token on every consent, not only on the first.
        $redirect = Socialite::driver('google')
            ->scopes(self::GOOGLE_SCOPES)
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect();

        if ($request->header('X-Inertia')) {
            return Inertia::location($redirect->getTargetUrl());
        }

        return $redirect;
    }

    public function callback(Request $request): RedirectResponse
    {
        // Google redirects back with ?error=... when the user denies consent
        // or the authorization request fails (invalid scope, revoked client,
        // etc.). In those cases there is no code to exchange, so calling
        // Socialite's user() would throw. Short-circuit with a recoverable
        // flash error instead.
        if ($request->filled('error')) {
            return redirect()
                ->route('settings.calendar-integration')
                ->with('error', $this->humaniseOAuthError($request->string('error')->toString()));
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('settings.calendar-integration')
                ->with('error', __('Could not complete the Google Calendar connection. Please try again.'));
        }

        $existing = $request->user()->calendarIntegration;

        $refreshToken = $googleUser->refreshToken ?: $existing?->refresh_token;

        $tokenExpiresAt = $googleUser->expiresIn !== null
            ? now()->addSeconds((int) $googleUser->expiresIn)
            : null;

        CalendarIntegration::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'provider' => 'google',
            ],
            [
                'access_token' => $googleUser->token,
                'refresh_token' => $refreshToken,
                'token_expires_at' => $tokenExpiresAt,
                'google_account_email' => $googleUser->getEmail(),
            ],
        );

        return redirect()
            ->route('settings.calendar-integration')
            ->with('success', __('Google Calendar connected.'));
    }

    public function disconnect(Request $request): RedirectResponse
    {
        // TODO Session 2: stop webhook watch here
        $request->user()->calendarIntegration()->delete();

        return redirect()
            ->route('settings.calendar-integration')
            ->with('success', __('Google Calendar disconnected.'));
    }

    private function humaniseOAuthError(string $code): string
    {
        return match ($code) {
            'access_denied' => __('Google Calendar connection was cancelled.'),
            default => __('Google declined the connection request. Please try again.'),
        };
    }
}
