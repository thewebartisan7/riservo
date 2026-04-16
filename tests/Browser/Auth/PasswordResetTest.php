<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Browser\Support\BusinessSetup;

// Covers: GET/POST /forgot-password (password.request, password.email)
//         GET /reset-password/{token} (password.reset)
//         POST /reset-password (password.update)

it('sends a reset link when an admin submits their email', function () {
    Notification::fake();

    ['admin' => $admin] = BusinessSetup::createBusinessWithAdmin();

    $page = visit('/forgot-password');

    $page->assertSee("Let's sort it out")
        ->type('email', $admin->email)
        ->click('button[type="submit"]')
        ->assertSee('we sent you a password reset link')
        ->assertPathIs('/forgot-password')
        ->assertNoJavaScriptErrors();

    Notification::assertSentTo($admin, ResetPassword::class);
});

it('shows the same success flash for an unknown email (no enumeration, D-072)', function () {
    Notification::fake();

    $page = visit('/forgot-password')
        ->type('email', 'nobody@example.com')
        ->click('button[type="submit"]');

    $page->assertSee('we sent you a password reset link')
        ->assertPathIs('/forgot-password')
        ->assertNoJavaScriptErrors();

    Notification::assertNothingSent();
});

it('throttles the per-email bucket after five submissions (D-072)', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'throttle-pw@example.com']);

    $page = visit('/forgot-password');

    for ($i = 0; $i < 5; $i++) {
        $page->type('email', $user->email)
            ->click('button[type="submit"]');
    }

    $page->type('email', $user->email)
        ->click('button[type="submit"]');

    $page->assertSee('Too many requests')
        ->assertNoJavaScriptErrors();
});

it('resets the password via a valid token and redirects to /login', function () {
    $user = User::factory()->create();

    // Mint a real password reset token via the broker (mirrors what the app does).
    $token = Password::broker()->createToken($user);

    $page = visit('/reset-password/'.$token.'?email='.urlencode($user->email));

    $page->assertSee('Reset password')
        ->type('password', 'new-password-456')
        ->type('password_confirmation', 'new-password-456')
        ->click('button[type="submit"]')
        ->assertPathIs('/login')
        ->assertNoJavaScriptErrors();

    expect(Hash::check('new-password-456', $user->fresh()->password))->toBeTrue();
});

it('shows a password-mismatch error when the confirmation does not match', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $page = visit('/reset-password/'.$token.'?email='.urlencode($user->email));

    $page->type('password', 'new-password-456')
        ->type('password_confirmation', 'different-000')
        ->click('button[type="submit"]')
        ->assertSee('password')
        ->assertPathBeginsWith('/reset-password/')
        ->assertNoJavaScriptErrors();

    // Password should not have changed.
    expect(Hash::check('new-password-456', $user->fresh()->password))->toBeFalse();
});

it('shows an error when the reset token is invalid', function () {
    $user = User::factory()->create();

    $page = visit('/reset-password/invalid-token?email='.urlencode($user->email));

    $page->type('password', 'new-password-456')
        ->type('password_confirmation', 'new-password-456')
        ->click('button[type="submit"]')
        ->assertSee('token')
        ->assertNoJavaScriptErrors();

    expect(Hash::check('new-password-456', $user->fresh()->password))->toBeFalse();
});
