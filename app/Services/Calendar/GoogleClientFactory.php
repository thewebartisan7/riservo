<?php

namespace App\Services\Calendar;

use App\Models\CalendarIntegration;
use Google\Client;

/**
 * Build a Google\Client configured for a specific CalendarIntegration.
 *
 * The `setTokenCallback` binding (D-080, documented here per §4.2 of the
 * MVPC-2 plan) is the single guard against the SDK silently rotating an
 * access token and losing it on the next request. When Google hands the
 * SDK a fresh access token during a refresh, the callback fires — we
 * persist the new access token to the integration row and recompute the
 * expiry from the standard OAuth 2.0 `expires_in` window (3600s).
 *
 * The SDK's callback signature is `function ($cacheKey, $accessToken)` —
 * it does not include expiry. Google access tokens are consistently 1h;
 * persisting `now() + 3600s` is accurate enough for the eligibility check
 * in PushBookingToCalendarJob and is verified by tests.
 */
class GoogleClientFactory
{
    public function make(CalendarIntegration $integration): Client
    {
        $client = new Client;
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect'));
        $client->setAccessType('offline');
        $client->setIncludeGrantedScopes(true);
        $client->addScope([
            'openid',
            'email',
            'https://www.googleapis.com/auth/calendar.events',
            'https://www.googleapis.com/auth/calendar',
        ]);

        $accessToken = [
            'access_token' => $integration->access_token,
            'refresh_token' => $integration->refresh_token,
        ];

        if ($integration->token_expires_at !== null) {
            $accessToken['expires_in'] = max(
                0,
                now()->diffInSeconds($integration->token_expires_at, false),
            );
            $accessToken['created'] = $integration->token_expires_at->copy()
                ->subSeconds((int) ($accessToken['expires_in'] ?: 3600))
                ->timestamp;
        }

        $client->setAccessToken($accessToken);

        $client->setTokenCallback(function (string $_cacheKey, string $newAccessToken) use ($integration): void {
            $integration->forceFill([
                'access_token' => $newAccessToken,
                'token_expires_at' => now()->addSeconds(3600),
            ])->save();
        });

        return $client;
    }
}
