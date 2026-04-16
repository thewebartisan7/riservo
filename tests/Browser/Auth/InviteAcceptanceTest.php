<?php

declare(strict_types=1);

use App\Enums\BusinessMemberRole;
use App\Models\BusinessInvitation;
use App\Models\User;
use Tests\Browser\Support\BusinessSetup;

// Covers: GET /invite/{token} (invitation.show), POST /invite/{token} (invitation.accept).

it('renders the invitation page with business name and invited email visible', function () {
    ['business' => $business] = BusinessSetup::createBusinessWithAdmin(['name' => 'The Barber Studio']);

    $invitation = BusinessInvitation::factory()->create([
        'business_id' => $business->id,
        'email' => 'new-staff@example.com',
        'role' => BusinessMemberRole::Staff,
    ]);

    $page = visit('/invite/'.$invitation->token);

    $page->assertPathIs('/invite/'.$invitation->token)
        ->assertSee('Accept invitation')
        ->assertSee('The Barber Studio')
        ->assertValue('input[type="email"]', 'new-staff@example.com')
        ->assertNoJavaScriptErrors();
});

it('creates a staff member and redirects to /dashboard when the invitation is accepted', function () {
    ['business' => $business] = BusinessSetup::createBusinessWithAdmin();

    $invitation = BusinessInvitation::factory()->create([
        'business_id' => $business->id,
        'email' => 'accepter@example.com',
        'role' => BusinessMemberRole::Staff,
    ]);

    $page = visit('/invite/'.$invitation->token);

    $page->type('name', 'New Staff')
        ->type('password', 'password123')
        ->type('password_confirmation', 'password123')
        ->click('button[type="submit"]')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();

    $user = User::where('email', 'accepter@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('New Staff')
        ->and($user->hasVerifiedEmail())->toBeTrue();

    $pivot = $user->businesses()->where('businesses.id', $business->id)->first()->pivot;
    expect($pivot->role)->toBe(BusinessMemberRole::Staff);

    expect($invitation->fresh()->isAccepted())->toBeTrue();
});

it('shows an error page when the invitation has expired (D-036)', function () {
    ['business' => $business] = BusinessSetup::createBusinessWithAdmin();

    $invitation = BusinessInvitation::factory()
        ->expired()
        ->create([
            'business_id' => $business->id,
            'email' => 'late@example.com',
        ]);

    $page = visit('/invite/'.$invitation->token);

    // Laravel's default 410 page renders "410" — custom message is hidden when debug=false.
    $page->assertSee('410');

    // No user was created.
    expect(User::where('email', 'late@example.com')->exists())->toBeFalse();
});

it('shows an error page when the invitation has already been accepted (one-time use)', function () {
    ['business' => $business] = BusinessSetup::createBusinessWithAdmin();

    $invitation = BusinessInvitation::factory()
        ->accepted()
        ->create([
            'business_id' => $business->id,
            'email' => 'already@example.com',
        ]);

    $page = visit('/invite/'.$invitation->token);

    // Laravel's default 410 page renders "410" — custom message is hidden when debug=false.
    $page->assertSee('410');
});

it('shows password-strength validation errors on the set-password form', function () {
    ['business' => $business] = BusinessSetup::createBusinessWithAdmin();

    $invitation = BusinessInvitation::factory()->create([
        'business_id' => $business->id,
        'email' => 'weakpw@example.com',
    ]);

    $page = visit('/invite/'.$invitation->token);

    $page->type('name', 'Weak Password User')
        ->type('password', 'short')
        ->type('password_confirmation', 'short')
        ->click('button[type="submit"]')
        ->assertSee('password')
        ->assertPathIs('/invite/'.$invitation->token)
        ->assertNoJavaScriptErrors();

    expect(User::where('email', 'weakpw@example.com')->exists())->toBeFalse();
});
