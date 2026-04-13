<?php

use App\Enums\BusinessUserRole;
use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\User;

test('invitation page can be rendered for valid token', function () {
    $this->withoutVite();
    $invitation = BusinessInvitation::factory()->create();

    $response = $this->get('/invite/'.$invitation->token);

    $response->assertStatus(200);
});

test('invitation page returns 404 for invalid token', function () {
    $response = $this->get('/invite/non-existent-token');

    $response->assertStatus(404);
});

test('expired invitation returns 410', function () {
    $invitation = BusinessInvitation::factory()->expired()->create();

    $response = $this->get('/invite/'.$invitation->token);

    $response->assertStatus(410);
});

test('already accepted invitation returns 410', function () {
    $invitation = BusinessInvitation::factory()->accepted()->create();

    $response = $this->get('/invite/'.$invitation->token);

    $response->assertStatus(410);
});

test('invitation can be accepted', function () {
    $business = Business::factory()->create();
    $invitation = BusinessInvitation::factory()->create([
        'business_id' => $business->id,
        'email' => 'collab@example.com',
        'role' => BusinessUserRole::Collaborator,
    ]);

    $response = $this->post('/invite/'.$invitation->token, [
        'name' => 'New Collaborator',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $this->assertAuthenticated();

    $user = User::where('email', 'collab@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('New Collaborator')
        ->and($user->hasVerifiedEmail())->toBeTrue();

    // Check business pivot
    expect($user->businesses()->first()->id)->toBe($business->id);
    expect($user->businesses()->first()->pivot->role)->toBe(BusinessUserRole::Collaborator);

    // Invitation marked as accepted
    expect($invitation->fresh()->isAccepted())->toBeTrue();

    $response->assertRedirect('/dashboard');
});

test('invitation acceptance validates input', function () {
    $invitation = BusinessInvitation::factory()->create();

    $response = $this->post('/invite/'.$invitation->token, [
        'name' => '',
        'password' => 'short',
        'password_confirmation' => 'mismatch',
    ]);

    $response->assertSessionHasErrors(['name', 'password']);
    $this->assertGuest();
});
