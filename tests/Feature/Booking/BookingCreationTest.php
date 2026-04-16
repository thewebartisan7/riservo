<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\ConfirmationMode;
use App\Enums\DayOfWeek;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create(['timezone' => 'Europe/Zurich']);
    $this->staff = User::factory()->create(['name' => 'Alice']);
    $this->provider = attachProvider($this->business, $this->staff);

    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'open_time' => '09:00',
        'close_time' => '18:00',
    ]);

    AvailabilityRule::factory()->create([
        'provider_id' => $this->provider->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '09:00',
        'end_time' => '18:00',
    ]);

    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 60,
    ]);
    $this->provider->services()->attach($this->service);

    // Fix time to Monday morning
    $this->travelTo(CarbonImmutable::parse('2026-04-13 08:00', 'Europe/Zurich'));
});

function validBookingData(array $overrides = []): array
{
    return array_merge([
        'service_id' => test()->service->id,
        'provider_id' => test()->provider->id,
        'date' => '2026-04-13',
        'time' => '10:00',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+41 79 123 45 67',
        'notes' => 'First visit',
        'website' => '',
    ], $overrides);
}

test('creates booking with auto-confirmation', function () {
    Notification::fake();

    $response = $this->postJson('/booking/'.$this->business->slug.'/book', validBookingData());

    $response->assertStatus(201)
        ->assertJsonStructure(['token', 'redirect_url', 'status'])
        ->assertJsonPath('status', 'confirmed');

    expect(Booking::count())->toBe(1);

    $booking = Booking::first();
    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->source)->toBe(BookingSource::Riservo)
        ->and($booking->provider_id)->toBe($this->provider->id)
        ->and($booking->service_id)->toBe($this->service->id)
        ->and($booking->cancellation_token)->not->toBeNull()
        ->and($booking->notes)->toBe('First visit');

    expect(Customer::where('email', 'jane@example.com')->exists())->toBeTrue();
});

test('creates booking with manual confirmation sets pending status', function () {
    Notification::fake();
    $this->business->update(['confirmation_mode' => ConfirmationMode::Manual]);

    $response = $this->postJson('/booking/'.$this->business->slug.'/book', validBookingData());

    $response->assertStatus(201)
        ->assertJsonPath('status', 'pending');

    expect(Booking::first()->status)->toBe(BookingStatus::Pending);
});

test('reuses existing customer by email', function () {
    Notification::fake();
    Customer::factory()->create(['email' => 'jane@example.com', 'name' => 'Old Name', 'phone' => '000']);

    $this->postJson('/booking/'.$this->business->slug.'/book', validBookingData());

    // Only one customer record
    expect(Customer::where('email', 'jane@example.com')->count())->toBe(1);

    // Name and phone updated
    $customer = Customer::where('email', 'jane@example.com')->first();
    expect($customer->name)->toBe('Jane Doe')
        ->and($customer->phone)->toBe('+41 79 123 45 67');
});

test('auto-assigns provider when not specified', function () {
    Notification::fake();

    $response = $this->postJson('/booking/'.$this->business->slug.'/book', validBookingData([
        'provider_id' => null,
    ]));

    $response->assertStatus(201);

    $booking = Booking::first();
    expect($booking->provider_id)->toBe($this->provider->id);
});

test('returns 409 when slot is no longer available', function () {
    Notification::fake();

    // Create an existing booking blocking 10:00-11:00 CEST (= 08:00-09:00 UTC)
    $blocking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => Customer::factory()->create()->id,
        'starts_at' => '2026-04-13 08:00:00',
        'ends_at' => '2026-04-13 09:00:00',
    ]);

    $response = $this->postJson('/booking/'.$this->business->slug.'/book', validBookingData());

    $response->assertStatus(409);
    expect(Booking::count())->toBe(1);
});

test('honeypot field filled returns 422', function () {
    $response = $this->postJson('/booking/'.$this->business->slug.'/book', validBookingData([
        'website' => 'https://spam.com',
    ]));

    $response->assertStatus(422);
    expect(Booking::count())->toBe(0);
});

test('validates required fields', function () {
    $response = $this->postJson('/booking/'.$this->business->slug.'/book', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['service_id', 'date', 'time', 'name', 'email', 'phone']);
});

test('booking source is riservo', function () {
    Notification::fake();

    $this->postJson('/booking/'.$this->business->slug.'/book', validBookingData());

    expect(Booking::first()->source)->toBe(BookingSource::Riservo);
});

test('cancellation token is generated', function () {
    Notification::fake();

    $response = $this->postJson('/booking/'.$this->business->slug.'/book', validBookingData());

    $token = $response->json('token');
    expect($token)->not->toBeNull();
    expect(Booking::where('cancellation_token', $token)->exists())->toBeTrue();
});

test('confirmation notification is sent', function () {
    Notification::fake();

    $this->postJson('/booking/'.$this->business->slug.'/book', validBookingData());

    Notification::assertSentOnDemand(BookingConfirmedNotification::class, function ($notification, $channels, $notifiable) {
        return $notifiable->routes['mail'] === 'jane@example.com';
    });
});

test('stores correct UTC times', function () {
    Notification::fake();

    $this->postJson('/booking/'.$this->business->slug.'/book', validBookingData([
        'date' => '2026-04-13',
        'time' => '10:00',
    ]));

    $booking = Booking::first();
    // Europe/Zurich is UTC+2 in April (CEST)
    expect($booking->starts_at->format('H:i'))->toBe('08:00')
        ->and($booking->ends_at->format('H:i'))->toBe('09:00');
});

test('links customer to authenticated user', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/booking/'.$this->business->slug.'/book', validBookingData());

    $customer = Customer::where('email', 'jane@example.com')->first();
    expect($customer->user_id)->toBe($user->id);
});
