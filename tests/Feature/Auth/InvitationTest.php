<?php

use App\Enums\BusinessMemberRole;
use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\Provider;
use App\Models\Service;
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
        'email' => 'newstaff@example.com',
        'role' => BusinessMemberRole::Staff,
    ]);

    $response = $this->post('/invite/'.$invitation->token, [
        'name' => 'New Staff Member',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $this->assertAuthenticated();

    $user = User::where('email', 'newstaff@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('New Staff Member')
        ->and($user->hasVerifiedEmail())->toBeTrue();

    // Check business pivot
    expect($user->businesses()->first()->id)->toBe($business->id);
    expect($user->businesses()->first()->pivot->role)->toBe(BusinessMemberRole::Staff);

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

test('existing user accepting invite does not recreate user or touch user fields', function () {
    $originalBusiness = Business::factory()->create();
    $verifiedAt = now()->subWeek()->startOfSecond();
    $existingUser = User::factory()->create([
        'email' => 'existing@example.com',
        'name' => 'Original Name',
        'email_verified_at' => $verifiedAt,
    ]);
    attachAdmin($originalBusiness, $existingUser);
    $originalPasswordHash = $existingUser->password;

    $newBusiness = Business::factory()->create();
    $service = Service::factory()->create(['business_id' => $newBusiness->id]);
    $invitation = BusinessInvitation::factory()->create([
        'business_id' => $newBusiness->id,
        'email' => 'existing@example.com',
        'role' => BusinessMemberRole::Staff,
        'service_ids' => [$service->id],
    ]);

    $response = $this->post('/invite/'.$invitation->token, [
        'password' => 'password',
    ]);

    $response->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($existingUser);

    $existingUser->refresh();
    expect(User::where('email', 'existing@example.com')->count())->toBe(1);
    expect($existingUser->name)->toBe('Original Name');
    expect($existingUser->password)->toBe($originalPasswordHash);
    expect($existingUser->email_verified_at->equalTo($verifiedAt))->toBeTrue();

    expect($newBusiness->members()->where('users.id', $existingUser->id)->exists())->toBeTrue();
    expect($newBusiness->members()->where('users.id', $existingUser->id)->first()->pivot->role)
        ->toBe(BusinessMemberRole::Staff);

    $provider = Provider::where('business_id', $newBusiness->id)
        ->where('user_id', $existingUser->id)
        ->first();
    expect($provider)->not->toBeNull();
    expect($provider->services()->pluck('services.id')->toArray())->toBe([$service->id]);

    expect(session('current_business_id'))->toBe($newBusiness->id);
    expect($originalBusiness->members()->where('users.id', $existingUser->id)->exists())->toBeTrue();
    expect($invitation->fresh()->isAccepted())->toBeTrue();
});

test('existing user invite rejects wrong password and does not attach', function () {
    $business = Business::factory()->create();
    $existingUser = User::factory()->create(['email' => 'existing@example.com']);

    $invitation = BusinessInvitation::factory()->create([
        'business_id' => $business->id,
        'email' => 'existing@example.com',
    ]);

    $response = $this->post('/invite/'.$invitation->token, [
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors(['password']);
    $this->assertGuest();

    expect($business->members()->where('users.id', $existingUser->id)->exists())->toBeFalse();
    expect(Provider::where('business_id', $business->id)->where('user_id', $existingUser->id)->exists())->toBeFalse();
    expect($invitation->fresh()->isAccepted())->toBeFalse();
});

test('cannot accept existing-user invite while signed in as a different user', function () {
    $business = Business::factory()->create();
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $other = User::factory()->create(['email' => 'other@example.com']);

    $invitation = BusinessInvitation::factory()->create([
        'business_id' => $business->id,
        'email' => 'invitee@example.com',
    ]);

    $response = $this->actingAs($other)->post('/invite/'.$invitation->token, [
        'password' => 'password',
    ]);

    $response->assertRedirect('/invite/'.$invitation->token);
    $response->assertSessionHas('error');

    expect($business->members()->where('users.id', $invitee->id)->exists())->toBeFalse();
    expect($business->members()->where('users.id', $other->id)->exists())->toBeFalse();
    expect($invitation->fresh()->isAccepted())->toBeFalse();
});

test('existing user already signed in as invitee accepts without password', function () {
    $business = Business::factory()->create();
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);

    $invitation = BusinessInvitation::factory()->create([
        'business_id' => $business->id,
        'email' => 'invitee@example.com',
    ]);

    $response = $this->actingAs($invitee)->post('/invite/'.$invitation->token);

    $response->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($invitee);

    expect($business->members()->where('users.id', $invitee->id)->exists())->toBeTrue();
    expect(Provider::where('business_id', $business->id)->where('user_id', $invitee->id)->exists())->toBeTrue();
    expect(session('current_business_id'))->toBe($business->id);
    expect($invitation->fresh()->isAccepted())->toBeTrue();
});

test('accept-invitation page signals new-user vs existing-user branch', function () {
    $this->withoutVite();

    $business = Business::factory()->create();
    $newUserInvite = BusinessInvitation::factory()->create([
        'business_id' => $business->id,
        'email' => 'brand-new@example.com',
    ]);
    $existingUserInvite = BusinessInvitation::factory()->create([
        'business_id' => $business->id,
        'email' => 'already-here@example.com',
    ]);
    User::factory()->create(['email' => 'already-here@example.com']);

    $this->get('/invite/'.$newUserInvite->token)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('isExistingUser', false));

    $this->get('/invite/'.$existingUserInvite->token)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('isExistingUser', true));
});
