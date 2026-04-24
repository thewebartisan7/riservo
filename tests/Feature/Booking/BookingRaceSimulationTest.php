<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Services\SlotGeneratorService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create(['timezone' => 'Europe/Zurich']);
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);

    $this->staff = User::factory()->create();
    $this->provider = attachProvider($this->business, $this->staff);

    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
    ]);
    $this->provider->services()->attach($this->service);

    $this->travelTo(CarbonImmutable::parse('2026-05-01 08:00', 'Europe/Zurich'));

    // Mock the slot generator to always return 10:00 as available.
    // This simulates the race: both concurrent requests pass the fast-fail.
    $slotTime = CarbonImmutable::parse('2026-05-01 10:00', 'Europe/Zurich');
    $providerRef = $this->provider;
    $this->mock(SlotGeneratorService::class, function (MockInterface $mock) use ($slotTime, $providerRef) {
        $mock->shouldReceive('getAvailableSlots')->andReturn([$slotTime]);
        $mock->shouldReceive('assignProvider')->andReturn($providerRef);
    });
});

test('public booking race: first POST wins, second returns 409', function () {
    Notification::fake();

    $payload = [
        'service_id' => $this->service->id,
        'provider_id' => $this->provider->id,
        'date' => '2026-05-01',
        'time' => '10:00',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+41 79 123 45 67',
        'website' => '',
    ];

    $first = $this->postJson('/booking/'.$this->business->slug.'/book', $payload);
    $first->assertStatus(201);

    $second = $this->postJson('/booking/'.$this->business->slug.'/book', array_merge($payload, [
        'email' => 'jack@example.com',
        'name' => 'Jack Doe',
    ]));

    // PAYMENTS Session 5 Round 3: slot-gone flows through
    // ValidationException → 422 with `slot_taken` key (Inertia-native
    // useHttp shape).
    $second->assertStatus(422)
        ->assertJsonValidationErrors(['slot_taken']);

    expect(Booking::count())->toBe(1);
});

test('public booking race without provider_id: auto-assign still hits constraint on second write', function () {
    Notification::fake();

    $payload = [
        'service_id' => $this->service->id,
        'date' => '2026-05-01',
        'time' => '10:00',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+41 79 123 45 67',
        'website' => '',
    ];

    $first = $this->postJson('/booking/'.$this->business->slug.'/book', $payload);
    $first->assertStatus(201);

    $second = $this->postJson('/booking/'.$this->business->slug.'/book', array_merge($payload, [
        'email' => 'jack@example.com',
        'name' => 'Jack Doe',
    ]));

    // PAYMENTS Session 5 Round 3: slot-gone flows through
    // ValidationException → 422 with `slot_taken` key (Inertia-native
    // useHttp shape).
    $second->assertStatus(422)
        ->assertJsonValidationErrors(['slot_taken']);

    expect(Booking::count())->toBe(1);
});

test('dashboard manual booking race: first POST wins, second redirects with error', function () {
    Notification::fake();

    $payload = [
        'customer_name' => 'Jane Doe',
        'customer_email' => 'jane@example.com',
        'customer_phone' => '+41 79 123 45 67',
        'service_id' => $this->service->id,
        'provider_id' => $this->provider->id,
        'date' => '2026-05-01',
        'time' => '10:00',
    ];

    $first = $this->actingAs($this->admin)->post('/dashboard/bookings', $payload);
    $first->assertRedirect('/dashboard/bookings');
    $first->assertSessionHas('success');

    $second = $this->actingAs($this->admin)->post('/dashboard/bookings', array_merge($payload, [
        'customer_email' => 'jack@example.com',
        'customer_name' => 'Jack Doe',
    ]));

    $second->assertRedirect();
    $second->assertSessionHas('error', __('This time slot is no longer available. Please select another time.'));

    expect(Booking::count())->toBe(1);
});
