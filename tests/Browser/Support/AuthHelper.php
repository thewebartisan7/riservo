<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Browser-test authentication helpers. Each method accepts the current `$page`
 * (a Pest Browser webpage) and returns the resulting page after the action so
 * callers can chain assertions (e.g. `->assertPathIs('/dashboard')`).
 */
final class AuthHelper
{
    /**
     * Submit the /login form with email + password. Works for admin, staff, and
     * customer users — the resulting redirect is determined by the controller.
     */
    public static function loginAs(mixed $page, User $user, string $password = 'password'): mixed
    {
        return visit('/login')
            ->type('email', $user->email)
            ->type('password', $password)
            ->submit()
            ->waitForEvent('load');
    }

    /**
     * Issue a fresh magic-link token for $user and visit the signed verify URL
     * directly — mirrors the URL `MagicLinkController::store` embeds in the
     * `MagicLinkNotification` (see D-037).
     */
    public static function loginViaMagicLink(mixed $page, User $user): mixed
    {
        $token = Str::random(64);
        $user->forceFill(['magic_link_token' => $token])->save();

        $url = URL::temporarySignedRoute(
            'magic-link.verify',
            now()->addMinutes(15),
            ['user' => $user->id, 'token' => $token],
        );

        return visit($url);
    }

    /**
     * Submit the /logout form by clicking the visible sign-out control. Callers
     * must already be on a page that renders the logout control (dashboard,
     * settings, etc.).
     */
    public static function logout(mixed $page): mixed
    {
        return $page->press('Sign out');
    }

    /**
     * Log in a customer-role user (a User with a linked Customer row — see
     * `User::isCustomer()` and D-074). The LoginController redirects customers
     * to `/my-bookings`.
     */
    public static function loginAsCustomer(mixed $page, User $customerUser, string $password = 'password'): mixed
    {
        return self::loginAs($page, $customerUser, $password);
    }
}
