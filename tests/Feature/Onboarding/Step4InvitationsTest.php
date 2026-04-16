<?php

use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\Service;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->business = Business::factory()->create(['onboarding_step' => 4]);
    attachAdmin($this->business, $this->user);
    $this->service = Service::factory()->create(['business_id' => $this->business->id]);
});

test('step 4 page renders with services', function () {
    $response = $this->actingAs($this->user)->get('/onboarding/step/4');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('onboarding/step-4')
        ->has('services', 1)
        ->has('pendingInvitations')
    );
});

test('step 4 creates invitations with service ids', function () {
    Notification::fake();

    $response = $this->actingAs($this->user)->postJson('/onboarding/step/4', [
        'invitations' => [
            ['email' => 'alice@example.com', 'service_ids' => [$this->service->id]],
            ['email' => 'bob@example.com', 'service_ids' => []],
        ],
    ]);

    $response->assertRedirect('/onboarding/step/5');

    expect(BusinessInvitation::where('business_id', $this->business->id)->count())->toBe(2);

    $aliceInvite = BusinessInvitation::where('email', 'alice@example.com')->first();
    expect($aliceInvite->service_ids)->toBe([$this->service->id]);

    $bobInvite = BusinessInvitation::where('email', 'bob@example.com')->first();
    expect($bobInvite->service_ids)->toBeEmpty();

    Notification::assertSentOnDemand(InvitationNotification::class, function ($notification, $channels, $notifiable) {
        return $notifiable->routes['mail'] === 'alice@example.com';
    });

    $this->business->refresh();
    expect($this->business->onboarding_step)->toBe(5);
});

test('step 4 skip with empty invitations advances step', function () {
    $response = $this->actingAs($this->user)->postJson('/onboarding/step/4', [
        'invitations' => [],
    ]);

    $response->assertRedirect('/onboarding/step/5');

    expect(BusinessInvitation::where('business_id', $this->business->id)->count())->toBe(0);

    $this->business->refresh();
    expect($this->business->onboarding_step)->toBe(5);
});

test('step 4 does not create duplicate invitation for same email', function () {
    Notification::fake();

    BusinessInvitation::factory()->create([
        'business_id' => $this->business->id,
        'email' => 'existing@example.com',
        'expires_at' => now()->addHours(48),
    ]);

    $this->actingAs($this->user)->postJson('/onboarding/step/4', [
        'invitations' => [
            ['email' => 'existing@example.com', 'service_ids' => []],
        ],
    ]);

    expect(BusinessInvitation::where('business_id', $this->business->id)
        ->where('email', 'existing@example.com')
        ->count())->toBe(1);
});
