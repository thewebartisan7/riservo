<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\MagicLinkNotification;
use Illuminate\Support\Facades\Notification;
use Tests\Browser\Support\BusinessSetup;

// Covers: GET /magic-link (magic-link.create), POST /magic-link (magic-link.store).

it('sends a magic link when an admin submits their email and shows a success flash', function () {
    Notification::fake();

    ['admin' => $admin] = BusinessSetup::createBusinessWithAdmin();

    $page = visit('/magic-link');

    $page->assertSee('No password needed')
        ->type('email', $admin->email)
        ->click('button[type="submit"]')
        ->assertSee('we sent you a login link')
        ->assertPathIs('/magic-link')
        ->assertNoJavaScriptErrors();

    Notification::assertSentTo($admin, MagicLinkNotification::class);
});

it('sends a magic link when a staff member submits their email', function () {
    Notification::fake();

    ['staff' => $staffCollection] = BusinessSetup::createBusinessWithStaff(1);
    $staff = $staffCollection->first();

    $page = visit('/magic-link')
        ->type('email', $staff->email)
        ->click('button[type="submit"]');

    $page->assertSee('we sent you a login link')
        ->assertPathIs('/magic-link')
        ->assertNoJavaScriptErrors();

    Notification::assertSentTo($staff, MagicLinkNotification::class);
});

it('shows the same success flash for an unknown email (no enumeration, D-072)', function () {
    Notification::fake();

    $page = visit('/magic-link')
        ->type('email', 'nobody@example.com')
        ->click('button[type="submit"]');

    $page->assertSee('we sent you a login link')
        ->assertPathIs('/magic-link')
        ->assertNoJavaScriptErrors();

    Notification::assertNothingSent();
});

it('throttles the per-email bucket after five submissions within the decay window', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'throttle@example.com']);

    $page = visit('/magic-link');

    for ($i = 0; $i < 5; $i++) {
        $page->type('email', $user->email)
            ->click('button[type="submit"]');
    }

    // Sixth submit — per-email bucket should now trip.
    $page->type('email', $user->email)
        ->click('button[type="submit"]');

    $page->assertSee('Too many requests')
        ->assertPathIs('/magic-link')
        ->assertNoJavaScriptErrors();
});

it('throttles the per-IP bucket after twenty submissions with rotating emails', function () {
    Notification::fake();

    $page = visit('/magic-link');

    for ($i = 1; $i <= 20; $i++) {
        $page->type('email', "rotating{$i}@example.com")
            ->click('button[type="submit"]');
    }

    // Twenty-first submit — per-IP bucket should now trip.
    $page->type('email', 'rotating21@example.com')
        ->click('button[type="submit"]');

    $page->assertSee('Too many requests')
        ->assertPathIs('/magic-link')
        ->assertNoJavaScriptErrors();
});
