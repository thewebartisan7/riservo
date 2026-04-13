<?php

use App\Models\Customer;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

test('magic link page can be rendered', function () {
    $this->withoutVite();

    $response = $this->get('/magic-link');

    $response->assertStatus(200);
});

test('magic link is sent to existing user', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'user@example.com']);

    $response = $this->post('/magic-link', ['email' => 'user@example.com']);

    $response->assertSessionHas('status');
    Notification::assertSentTo($user, MagicLinkNotification::class);
});

test('magic link request for non-existent email returns same success message', function () {
    Notification::fake();

    $response = $this->post('/magic-link', ['email' => 'nobody@example.com']);

    $response->assertSessionHas('status');
    Notification::assertNothingSent();
});

test('user can login via valid magic link', function () {
    $token = 'test-magic-token-123';
    $user = User::factory()->create(['magic_link_token' => $token]);

    $url = URL::temporarySignedRoute(
        'magic-link.verify',
        now()->addMinutes(15),
        ['user' => $user->id, 'token' => $token],
    );

    $response = $this->get($url);

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->magic_link_token)->toBeNull();
});

test('magic link is one-time use', function () {
    $this->withoutVite();
    $token = 'test-magic-token-456';
    $user = User::factory()->create(['magic_link_token' => $token]);

    $url = URL::temporarySignedRoute(
        'magic-link.verify',
        now()->addMinutes(15),
        ['user' => $user->id, 'token' => $token],
    );

    // First use succeeds
    $this->get($url);
    $this->assertAuthenticatedAs($user);

    // Logout and try again
    $this->post('/logout');

    $response = $this->get($url);
    $response->assertStatus(403);
});

test('magic link auto-creates user for guest customer', function () {
    Notification::fake();
    $customer = Customer::factory()->create([
        'email' => 'guest@example.com',
        'name' => 'Guest Customer',
        'user_id' => null,
    ]);

    $this->post('/magic-link', ['email' => 'guest@example.com']);

    $customer->refresh();
    expect($customer->user_id)->not->toBeNull();

    $user = User::find($customer->user_id);
    expect($user->email)->toBe('guest@example.com')
        ->and($user->name)->toBe('Guest Customer')
        ->and($user->password)->toBeNull();

    Notification::assertSentTo($user, MagicLinkNotification::class);
});

test('magic link marks email as verified', function () {
    $token = 'verify-token-789';
    $user = User::factory()->unverified()->create([
        'magic_link_token' => $token,
    ]);

    $url = URL::temporarySignedRoute(
        'magic-link.verify',
        now()->addMinutes(15),
        ['user' => $user->id, 'token' => $token],
    );

    $this->get($url);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});
