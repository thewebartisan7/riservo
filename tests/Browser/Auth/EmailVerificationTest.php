<?php

declare(strict_types=1);

use App\Models\Business;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

// Covers: GET /email/verify (verification.notice),
//         GET /email/verify/{id}/{hash} (verification.verify),
//         POST /email/verification-notification (verification.send).
//
// NOTE: The E2E-0 AuthHelper has a bug (uses $page->visit which does not
// exist) so these tests log in inline via the /login form.

it('redirects an unverified admin from /dashboard to /email/verify', function () {
    $user = User::factory()->unverified()->create();
    $business = Business::factory()->onboarded()->create();
    attachAdmin($business, $user);

    $page = visit('/login')
        ->type('email', $user->email)
        ->type('password', 'password')
        ->click('button[type="submit"]');

    $page->navigate('/dashboard')
        ->assertPathIs('/email/verify')
        ->assertSee('Verify your email')
        ->assertNoJavaScriptErrors();
});

it('resends the verification email and shows a success flash', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $business = Business::factory()->create();
    attachAdmin($business, $user);

    $page = visit('/login')
        ->type('email', $user->email)
        ->type('password', 'password')
        ->click('button[type="submit"]');

    $page->assertPathIs('/email/verify')
        ->click('button[type="submit"]')
        ->assertSee('A new verification link has been sent')
        ->assertNoJavaScriptErrors();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('marks email as verified when following a valid signed verification link', function () {
    $user = User::factory()->unverified()->create();
    $business = Business::factory()->create(['onboarding_step' => 1]);
    attachAdmin($business, $user);

    // Log in first so the /email/verify/... route (auth middleware) passes.
    $page = visit('/login')
        ->type('email', $user->email)
        ->type('password', 'password')
        ->click('button[type="submit"]');

    $page->assertPathIs('/email/verify');

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
    );

    $page->navigate($url);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    // Admin with unfinished onboarding should land on the onboarding step.
    $page->assertPathBeginsWith('/onboarding/step/')
        ->assertNoJavaScriptErrors();
});

it('rejects a tampered verification link with a 403', function () {
    $user = User::factory()->unverified()->create();
    $business = Business::factory()->create();
    attachAdmin($business, $user);

    $page = visit('/login')
        ->type('email', $user->email)
        ->type('password', 'password')
        ->click('button[type="submit"]');

    $page->assertPathIs('/email/verify');

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
    );

    // Tamper with the signature.
    $tampered = $url.'x';

    $page->navigate($tampered);

    // Laravel renders a 403 for invalid signed URLs.
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});
