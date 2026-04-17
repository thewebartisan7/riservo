<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DayOfWeek;
use App\Jobs\Calendar\PushBookingToCalendarJob;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\CalendarIntegration;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingRescheduledNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create(['timezone' => 'Europe/Zurich']);
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);

    $this->staff = User::factory()->create();
    $this->provider = attachProvider($this->business, $this->staff);

    // Wednesday business hours + provider availability 09:00 – 18:00.
    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Wednesday->value,
        'open_time' => '09:00',
        'close_time' => '18:00',
    ]);
    AvailabilityRule::factory()->create([
        'provider_id' => $this->provider->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Wednesday->value,
        'start_time' => '09:00',
        'end_time' => '18:00',
    ]);

    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'is_active' => true,
        'duration_minutes' => 60,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval_minutes' => 30,
    ]);
    $this->provider->services()->attach($this->service);

    $this->customer = Customer::factory()->create();

    // Wednesday 2026-04-15 08:00 Europe/Zurich.
    $this->travelTo(CarbonImmutable::parse('2026-04-15 08:00', 'Europe/Zurich'));

    $this->booking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 11:00', 'Europe/Zurich')->utc(),
        'source' => BookingSource::Manual,
    ]);
});

function reschedulePayload(CarbonImmutable $startsAtLocal, int $durationMinutes = 60): array
{
    return [
        'starts_at' => $startsAtLocal->utc()->toIso8601String(),
        'duration_minutes' => $durationMinutes,
    ];
}

test('admin reschedules confirmed booking to a free slot', function () {
    Notification::fake();
    Queue::fake();

    $newStart = CarbonImmutable::parse('2026-04-15 14:00', 'Europe/Zurich');

    $response = $this->actingAs($this->admin)
        ->patchJson("/dashboard/bookings/{$this->booking->id}/reschedule", reschedulePayload($newStart));

    $response->assertOk();
    $response->assertJsonPath('booking.id', $this->booking->id);

    $this->booking->refresh();
    expect($this->booking->starts_at->format('Y-m-d H:i'))
        ->toBe($newStart->utc()->format('Y-m-d H:i'));
    expect($this->booking->ends_at->format('Y-m-d H:i'))
        ->toBe($newStart->addHour()->utc()->format('Y-m-d H:i'));

    Notification::assertSentOnDemand(BookingRescheduledNotification::class);
});

test('admin reschedule dispatches PushBookingToCalendarJob when provider has a configured integration', function () {
    Notification::fake();
    Queue::fake();

    // Attach a configured calendar integration to the staff (provider user).
    CalendarIntegration::factory()->create([
        'user_id' => $this->staff->id,
        'business_id' => $this->business->id,
        'provider' => 'google',
        'google_account_email' => 'staff@example.com',
        'destination_calendar_id' => 'primary',
    ]);

    $newStart = CarbonImmutable::parse('2026-04-15 14:00', 'Europe/Zurich');

    $response = $this->actingAs($this->admin)
        ->patchJson("/dashboard/bookings/{$this->booking->id}/reschedule", reschedulePayload($newStart));

    $response->assertOk();

    Queue::assertPushed(PushBookingToCalendarJob::class, function ($job) {
        return $job->bookingId === $this->booking->id && $job->action === 'update';
    });
});

test('staff can reschedule their own booking', function () {
    Notification::fake();
    Queue::fake();

    $newStart = CarbonImmutable::parse('2026-04-15 15:00', 'Europe/Zurich');

    $response = $this->actingAs($this->staff)
        ->patchJson("/dashboard/bookings/{$this->booking->id}/reschedule", reschedulePayload($newStart));

    $response->assertOk();
    expect($this->booking->fresh()->starts_at->format('H:i'))->toBe('13:00'); // 15:00 Zurich → 13:00 UTC
});

test('staff cannot reschedule another providers booking', function () {
    Notification::fake();
    Queue::fake();

    $otherStaff = User::factory()->create();
    $otherProvider = attachProvider($this->business, $otherStaff);
    $this->service->providers()->attach($otherProvider);
    AvailabilityRule::factory()->create([
        'provider_id' => $otherProvider->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Wednesday->value,
        'start_time' => '09:00',
        'end_time' => '18:00',
    ]);

    $otherBooking = Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $otherProvider->id,
        'service_id' => $this->service->id,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 11:00', 'Europe/Zurich')->utc(),
    ]);

    $newStart = CarbonImmutable::parse('2026-04-15 14:00', 'Europe/Zurich');

    $response = $this->actingAs($this->staff)
        ->patchJson("/dashboard/bookings/{$otherBooking->id}/reschedule", reschedulePayload($newStart));

    $response->assertForbidden();
});

test('reschedule a booking whose provider is soft-deleted is refused with 422', function () {
    $this->provider->delete();

    $newStart = CarbonImmutable::parse('2026-04-15 14:00', 'Europe/Zurich');

    $response = $this->actingAs($this->admin)
        ->patchJson("/dashboard/bookings/{$this->booking->id}/reschedule", reschedulePayload($newStart));

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'This booking belongs to a deactivated provider and cannot be rescheduled.');
});

test('reschedule to an occupied slot returns 422 with availability error', function () {
    // Put another confirmed booking at 14:00–15:00 on the same provider.
    Booking::factory()->confirmed()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => $this->service->id,
        'customer_id' => Customer::factory()->create()->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 14:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 15:00', 'Europe/Zurich')->utc(),
    ]);

    $newStart = CarbonImmutable::parse('2026-04-15 14:00', 'Europe/Zurich');

    $response = $this->actingAs($this->admin)
        ->patchJson("/dashboard/bookings/{$this->booking->id}/reschedule", reschedulePayload($newStart));

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'That slot is not available. Pick another time.');
});

test('reschedule of a source google_calendar booking is refused with 422', function () {
    $externalBooking = Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => null,
        'customer_id' => null,
        'starts_at' => CarbonImmutable::parse('2026-04-15 13:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 14:00', 'Europe/Zurich')->utc(),
        'source' => BookingSource::GoogleCalendar,
        'status' => BookingStatus::Confirmed,
        'external_title' => 'External',
        'external_calendar_id' => 'abc',
    ]);

    $newStart = CarbonImmutable::parse('2026-04-15 15:00', 'Europe/Zurich');

    $response = $this->actingAs($this->admin)
        ->patchJson("/dashboard/bookings/{$externalBooking->id}/reschedule", reschedulePayload($newStart));

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'External calendar events cannot be rescheduled from riservo.');
});

test('resize that extends past the provider working window is refused', function () {
    // Provider availability ends at 18:00 Wednesday. Service default duration
    // is 60. A resize to 17:30 start + 90-minute duration would run to 19:00
    // — past the window. Must be refused, not silently accepted.
    $newStart = CarbonImmutable::parse('2026-04-15 17:30', 'Europe/Zurich');

    $response = $this->actingAs($this->admin)
        ->patchJson("/dashboard/bookings/{$this->booking->id}/reschedule", [
            'starts_at' => $newStart->utc()->toIso8601String(),
            'duration_minutes' => 90,
        ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('not available');

    // Original booking unchanged.
    expect($this->booking->fresh()->starts_at->format('Y-m-d H:i'))
        ->toBe('2026-04-15 08:00');
});

test('resize inside the provider window and under the service slot-interval is accepted', function () {
    // Same provider, window 09:00 – 18:00. Resize to 10:00 start + 90 min
    // fits (ends at 11:30) — no conflicts, inside window.
    Notification::fake();
    Queue::fake();

    $newStart = CarbonImmutable::parse('2026-04-15 10:00', 'Europe/Zurich');

    $response = $this->actingAs($this->admin)
        ->patchJson("/dashboard/bookings/{$this->booking->id}/reschedule", [
            'starts_at' => $newStart->utc()->toIso8601String(),
            'duration_minutes' => 90,
        ]);

    $response->assertOk();
    $this->booking->refresh();
    expect($this->booking->ends_at->format('H:i'))->toBe('09:30'); // 11:30 Zurich → 09:30 UTC
});

test('reschedule that does not snap to slot interval is refused', function () {
    // slot_interval_minutes = 30; try 10:17, which is not on the grid.
    $newStart = CarbonImmutable::parse('2026-04-15 14:17', 'Europe/Zurich');

    $response = $this->actingAs($this->admin)
        ->patchJson("/dashboard/bookings/{$this->booking->id}/reschedule", reschedulePayload($newStart));

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('grid');
});

test('reschedule cross-business booking returns 404', function () {
    $otherBusiness = Business::factory()->onboarded()->create();
    $otherAdmin = User::factory()->create();
    attachAdmin($otherBusiness, $otherAdmin);

    $newStart = CarbonImmutable::parse('2026-04-15 14:00', 'Europe/Zurich');

    $response = $this->actingAs($otherAdmin)
        ->patchJson("/dashboard/bookings/{$this->booking->id}/reschedule", reschedulePayload($newStart));

    $response->assertNotFound();
});

test('reschedule of a terminal-status booking is refused', function () {
    $this->booking->update(['status' => BookingStatus::Cancelled]);

    $newStart = CarbonImmutable::parse('2026-04-15 14:00', 'Europe/Zurich');

    $response = $this->actingAs($this->admin)
        ->patchJson("/dashboard/bookings/{$this->booking->id}/reschedule", reschedulePayload($newStart));

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('pending or confirmed');
});

test('reschedule straddling two days is refused', function () {
    // Start at 23:30 Wednesday, duration 60 — ends at 00:30 Thursday.
    $newStart = CarbonImmutable::parse('2026-04-15 23:30', 'Europe/Zurich');

    $response = $this->actingAs($this->admin)
        ->patchJson("/dashboard/bookings/{$this->booking->id}/reschedule", reschedulePayload($newStart, 60));

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('straddle two days');
});

test('GIST race: if a conflicting booking lands between the availability check and the UPDATE, the endpoint returns 422', function () {
    // Manufacture the race by inserting the conflicting booking inside the
    // transaction — the availability check has passed at that point but the
    // UPDATE has not fired. Use the `DB::afterCommit` hook sequencing or a
    // simpler trick: register a DB listener that fires on the first SELECT
    // against `bookings` inside the request, and inserts the conflict then.
    $collided = false;

    DB::listen(function ($query) use (&$collided) {
        // Fire once, on the blocking-bookings query that the reschedule flow
        // runs before the UPDATE. That query selects from `bookings` with
        // provider_id + status + starts_at + ends_at windowing.
        if (! $collided && str_contains(strtolower($query->sql), 'from "bookings"') && str_contains(strtolower($query->sql), 'provider_id')) {
            $collided = true;
            Booking::factory()->confirmed()->create([
                'business_id' => test()->business->id,
                'provider_id' => test()->provider->id,
                'service_id' => test()->service->id,
                'customer_id' => Customer::factory()->create()->id,
                'starts_at' => CarbonImmutable::parse('2026-04-15 14:00', 'Europe/Zurich')->utc(),
                'ends_at' => CarbonImmutable::parse('2026-04-15 15:00', 'Europe/Zurich')->utc(),
            ]);
        }
    });

    $newStart = CarbonImmutable::parse('2026-04-15 14:00', 'Europe/Zurich');

    $response = $this->actingAs($this->admin)
        ->patchJson("/dashboard/bookings/{$this->booking->id}/reschedule", reschedulePayload($newStart));

    // Whether the race lands on the pre-check (app-side) or the GIST
    // exclusion at UPDATE time (db-side), both are translated to 422 — one
    // honest error shape for the client (Inertia reserves 409 for
    // asset-version / external-redirect semantics, so the race-backstop
    // reuses the app-side's status code).
    expect($response->status())->toBe(422);
    $this->booking->refresh();
    expect($this->booking->starts_at->format('Y-m-d H:i'))->toBe('2026-04-15 08:00'); // original UTC
});

test('external booking reschedule does not dispatch the customer notification', function () {
    Notification::fake();
    Queue::fake();

    $externalBooking = Booking::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => $this->provider->id,
        'service_id' => null,
        'customer_id' => $this->customer->id,
        'starts_at' => CarbonImmutable::parse('2026-04-15 13:00', 'Europe/Zurich')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-04-15 14:00', 'Europe/Zurich')->utc(),
        'source' => BookingSource::GoogleCalendar,
        'status' => BookingStatus::Confirmed,
    ]);

    $newStart = CarbonImmutable::parse('2026-04-15 14:30', 'Europe/Zurich');

    $this->actingAs($this->admin)
        ->patchJson("/dashboard/bookings/{$externalBooking->id}/reschedule", reschedulePayload($newStart));

    Notification::assertNothingSent();
});
