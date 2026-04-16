<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;

// D-072: auth-recovery POSTs are throttled per-email AND per-IP independently.
// Either bucket exceeding its limit throws ValidationException on the email field.

beforeEach(function () {
    Notification::fake();
});

dataset('authRecoveryEndpoints', [
    'magic-link' => ['/magic-link'],
    'forgot-password' => ['/forgot-password'],
]);

test('per-email bucket blocks after five hits within the decay window', function (string $endpoint) {
    User::factory()->create(['email' => 'alice@example.com']);

    for ($i = 0; $i < 5; $i++) {
        $this->post($endpoint, ['email' => 'alice@example.com'])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();
    }

    $this->post($endpoint, ['email' => 'alice@example.com'])
        ->assertSessionHasErrors('email');
})->with('authRecoveryEndpoints');

test('per-IP bucket blocks after twenty hits with rotating emails', function (string $endpoint) {
    for ($i = 1; $i <= 20; $i++) {
        $this->post($endpoint, ['email' => "user{$i}@example.com"])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();
    }

    $this->post($endpoint, ['email' => 'user21@example.com'])
        ->assertSessionHasErrors('email');
})->with('authRecoveryEndpoints');

test('per-email and per-IP buckets are orthogonal', function () {
    // Four hits against alice@ — per-email 4/5, per-IP 4/20.
    for ($i = 0; $i < 4; $i++) {
        $this->post('/magic-link', ['email' => 'alice@example.com'])
            ->assertSessionHasNoErrors();
    }

    // One hit against bob@ from same IP — per-email for bob 1/5, per-IP 5/20; neither trips.
    $this->post('/magic-link', ['email' => 'bob@example.com'])
        ->assertSessionHasNoErrors();

    // Fifth alice@ still allowed (per-email 5/5 threshold reached on this hit).
    $this->post('/magic-link', ['email' => 'alice@example.com'])
        ->assertSessionHasNoErrors();

    // Sixth alice@ — per-email bucket now locked out.
    $this->post('/magic-link', ['email' => 'alice@example.com'])
        ->assertSessionHasErrors('email');
});

test('throttle keys are endpoint-scoped', function () {
    // Exhaust the magic-link per-email bucket for alice@.
    for ($i = 0; $i < 5; $i++) {
        $this->post('/magic-link', ['email' => 'alice@example.com']);
    }
    $this->post('/magic-link', ['email' => 'alice@example.com'])
        ->assertSessionHasErrors('email');

    // The forgot-password endpoint has an independent counter namespace — alice@ still works.
    $this->post('/forgot-password', ['email' => 'alice@example.com'])
        ->assertSessionHas('status')
        ->assertSessionHasNoErrors();
});

test('decay window frees the bucket', function () {
    // Exhaust the per-email bucket.
    for ($i = 0; $i < 5; $i++) {
        $this->post('/magic-link', ['email' => 'alice@example.com']);
    }
    $this->post('/magic-link', ['email' => 'alice@example.com'])
        ->assertSessionHasErrors('email');

    // Travel past the 15-minute decay window.
    $this->travel(16)->minutes();

    $this->post('/magic-link', ['email' => 'alice@example.com'])
        ->assertSessionHas('status')
        ->assertSessionHasNoErrors();
});
