<?php

use App\Enums\BookingStatus;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Provider;
use App\Models\User;
use App\Notifications\BookingReceivedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutVite();
    Notification::fake();
    // Monday 2026-04-13 08:00 Europe/Zurich — before the 10:00 booking slot.
    $this->travelTo(CarbonImmutable::parse('2026-04-13 08:00', 'Europe/Zurich'));
});

function soloHoursPayload(): array
{
    return [
        'hours' => collect(range(1, 7))->map(fn ($day) => [
            'day_of_week' => $day,
            'enabled' => $day <= 5,
            'windows' => $day <= 5 ? [['open_time' => '09:00', 'close_time' => '17:00']] : [],
        ])->all(),
    ];
}

function soloProviderSchedulePayload(): array
{
    return collect(range(1, 7))->map(fn ($day) => [
        'day_of_week' => $day,
        'enabled' => $day <= 5,
        'windows' => $day <= 5 ? [['open_time' => '09:00', 'close_time' => '17:00']] : [],
    ])->all();
}

test('solo owner can register, onboard with provider opt-in, launch, and receive a public booking', function () {
    // 1. Register.
    $this->post('/register', [
        'name' => 'Sam Solo',
        'email' => 'sam@example.com',
        'password' => 'secret-password-123',
        'password_confirmation' => 'secret-password-123',
        'business_name' => 'Sam Salon',
    ])->assertRedirect(route('verification.notice'));

    $user = User::where('email', 'sam@example.com')->firstOrFail();
    $user->forceFill(['email_verified_at' => now()])->save();

    $business = $user->currentBusiness();
    expect($business)->not->toBeNull();

    // 2. Step 1 — profile (keep the auto-generated slug).
    $this->actingAs($user)->post('/onboarding/step/1', [
        'name' => 'Sam Salon',
        'slug' => $business->slug,
        'description' => 'Haircuts and more',
        'phone' => null,
        'email' => null,
        'address' => null,
        'logo' => null,
    ])->assertRedirect(route('onboarding.show', ['step' => 2]));

    // 3. Step 2 — business hours Mon-Fri 09:00-17:00.
    $this->actingAs($user)->post('/onboarding/step/2', soloHoursPayload())
        ->assertRedirect(route('onboarding.show', ['step' => 3]));

    // 4. Step 3 — service + provider opt-in.
    $this->actingAs($user)->post('/onboarding/step/3', [
        'name' => 'Haircut',
        'duration_minutes' => 60,
        'price' => 50,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 60,
        'provider_opt_in' => true,
        'provider_schedule' => soloProviderSchedulePayload(),
    ])->assertRedirect(route('onboarding.show', ['step' => 4]));

    // 5. Step 4 — skip invitations.
    $this->actingAs($user)->post('/onboarding/step/4', ['invitations' => []])
        ->assertRedirect(route('onboarding.show', ['step' => 5]));

    // 6. Step 5 — launch.
    $this->actingAs($user)->post('/onboarding/step/5')
        ->assertRedirect(route('dashboard.welcome'));

    $business->refresh();
    expect($business->onboarding_completed_at)->not->toBeNull();

    $provider = Provider::where('business_id', $business->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($provider->trashed())->toBeFalse();
    expect(AvailabilityRule::where('provider_id', $provider->id)->count())->toBe(5);

    $service = $business->services()->firstOrFail();
    expect($provider->services()->where('services.id', $service->id)->exists())->toBeTrue();

    // 7. Public booking by a guest.
    $response = $this->postJson('/booking/'.$business->slug.'/book', [
        'service_id' => $service->id,
        'provider_id' => $provider->id,
        'date' => '2026-04-13',
        'time' => '10:00',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+41 79 123 45 67',
        'notes' => null,
        'website' => '',
    ]);

    $response->assertStatus(201)->assertJsonPath('status', 'confirmed');

    expect(Booking::count())->toBe(1);
    $booking = Booking::first();
    expect($booking->provider_id)->toBe($provider->id);
    expect($booking->service_id)->toBe($service->id);
    expect($booking->status)->toBe(BookingStatus::Confirmed);

    Notification::assertSentTo($user, BookingReceivedNotification::class);
});
