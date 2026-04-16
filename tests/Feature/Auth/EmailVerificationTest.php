<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

test('unverified user sees verification notice', function () {
    $this->withoutVite();
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get('/email/verify');

    $response->assertStatus(200);
});

test('verified user is redirected from verification notice', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $business = Business::factory()->create();
    attachAdmin($business, $user);

    $response = $this->actingAs($user)->get('/email/verify');

    $response->assertRedirect('/dashboard');
});

test('unverified user cannot access dashboard', function () {
    $this->withoutVite();
    $user = User::factory()->unverified()->create();
    $business = Business::factory()->create();
    attachAdmin($business, $user);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertRedirect('/email/verify');
});

test('email can be verified via signed link', function () {
    $this->withoutVite();
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
    );

    $response = $this->actingAs($user)->get($url);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect('/dashboard');
});

test('verification email can be resent', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post('/email/verification-notification');

    Notification::assertSentTo($user, VerifyEmail::class);
});
