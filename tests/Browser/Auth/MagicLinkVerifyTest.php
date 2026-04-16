<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Browser\Support\BusinessSetup;

// Covers: GET /magic-link/verify/{user} (magic-link.verify) — signed, one-time-use per D-037.

/**
 * Persist a fresh magic-link token on $user and return the signed verify URL
 * — mirrors what MagicLinkController::store does.
 */
$mintMagicLinkUrl = function (User $user, ?Carbon $expiry = null): string {
    $token = Str::random(64);
    $user->forceFill(['magic_link_token' => $token])->save();

    return URL::temporarySignedRoute(
        'magic-link.verify',
        $expiry ?? now()->addMinutes(15),
        ['user' => $user->id, 'token' => $token],
    );
};

it('logs an admin in via magic link and redirects to the dashboard', function () use ($mintMagicLinkUrl) {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $url = $mintMagicLinkUrl($admin);

    $page = visit($url);

    $page->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();

    expect($admin->fresh()->magic_link_token)->toBeNull();
});

it('logs a staff member in via magic link and redirects to the dashboard', function () use ($mintMagicLinkUrl) {
    ['staff' => $staffCollection] = BusinessSetup::createBusinessWithStaff(1);
    $staff = $staffCollection->first();

    $url = $mintMagicLinkUrl($staff);

    $page = visit($url);

    $page->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
});

it('logs a customer in via magic link and redirects to /my-bookings', function () use ($mintMagicLinkUrl) {
    $user = User::factory()->create();
    Customer::factory()->create([
        'user_id' => $user->id,
        'email' => $user->email,
    ]);

    $url = $mintMagicLinkUrl($user);

    $page = visit($url);

    $page->assertPathIs('/my-bookings')
        ->assertNoJavaScriptErrors();
});

it('is a one-time link: visiting it twice after logout fails with an invalid-link error', function () use ($mintMagicLinkUrl) {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $url = $mintMagicLinkUrl($admin);

    // First visit logs the user in.
    $page = visit($url);
    $page->assertPathIs('/dashboard');

    // Log out so the second visit is a fresh auth attempt.
    $page->click('button:has-text("Log out")');

    // Second visit of the same URL must fail because the token has been consumed.
    $second = visit($url);
    $second->assertSee('invalid or has already been used');
});

it('rejects an expired magic link', function () use ($mintMagicLinkUrl) {
    $admin = User::factory()->create();
    $url = $mintMagicLinkUrl($admin, now()->addMinutes(15));

    // Travel past the expiry window before visiting.
    $this->travel(20)->minutes();

    $page = visit($url);

    // Laravel aborts 403 on an expired signed URL.
    $page->assertSee('403');
});

it('rejects a magic link with a tampered signature', function () use ($mintMagicLinkUrl) {
    $admin = User::factory()->create();
    $url = $mintMagicLinkUrl($admin);

    // Append a character to the signature so the integrity check fails.
    $tampered = $url.'x';

    $page = visit($tampered);

    $page->assertSee('403');
});
