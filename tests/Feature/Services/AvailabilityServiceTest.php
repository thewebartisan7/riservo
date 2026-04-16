<?php

use App\Enums\DayOfWeek;
use App\Enums\ExceptionType;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Business;
use App\Models\BusinessHour;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->service = app(AvailabilityService::class);

    // Monday 2026-04-13 in Europe/Zurich
    $this->monday = CarbonImmutable::parse('2026-04-13', 'Europe/Zurich');
    $this->mondayDate = '2026-04-13';

    // Sunday 2026-04-19
    $this->sunday = CarbonImmutable::parse('2026-04-19', 'Europe/Zurich');
    $this->sundayDate = '2026-04-19';

    $this->business = Business::factory()->create(['timezone' => 'Europe/Zurich']);
    $this->staff = User::factory()->create();

    // Attach staff to business and create a provider
    $this->provider = attachProvider($this->business, $this->staff);
});

test('returns windows from provider weekly schedule', function () {
    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'open_time' => '08:00',
        'close_time' => '20:00',
    ]);

    AvailabilityRule::factory()->create([
        'provider_id' => $this->provider->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    $windows = $this->service->getAvailableWindows($this->business, $this->provider, $this->monday);

    expect($windows)->toHaveCount(1);
    expect($windows[0]->start->format('H:i'))->toBe('09:00');
    expect($windows[0]->end->format('H:i'))->toBe('17:00');
});

test('returns empty when provider has no rules for weekday', function () {
    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'open_time' => '09:00',
        'close_time' => '18:00',
    ]);

    // No AvailabilityRule for Monday
    $windows = $this->service->getAvailableWindows($this->business, $this->provider, $this->monday);

    expect($windows)->toBeEmpty();
});

test('returns empty when business is closed on that day', function () {
    // No BusinessHour for Monday
    AvailabilityRule::factory()->create([
        'provider_id' => $this->provider->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    $windows = $this->service->getAvailableWindows($this->business, $this->provider, $this->monday);

    expect($windows)->toBeEmpty();
});

test('supports multiple windows per day (morning + afternoon)', function () {
    // Business open with lunch break
    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'open_time' => '09:00',
        'close_time' => '13:00',
    ]);
    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'open_time' => '14:00',
        'close_time' => '18:00',
    ]);

    AvailabilityRule::factory()->create([
        'provider_id' => $this->provider->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '09:00',
        'end_time' => '13:00',
    ]);
    AvailabilityRule::factory()->create([
        'provider_id' => $this->provider->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '14:00',
        'end_time' => '18:00',
    ]);

    $windows = $this->service->getAvailableWindows($this->business, $this->provider, $this->monday);

    expect($windows)->toHaveCount(2);
    expect($windows[0]->start->format('H:i'))->toBe('09:00');
    expect($windows[0]->end->format('H:i'))->toBe('13:00');
    expect($windows[1]->start->format('H:i'))->toBe('14:00');
    expect($windows[1]->end->format('H:i'))->toBe('18:00');
});

test('business hours clip provider availability', function () {
    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'open_time' => '10:00',
        'close_time' => '16:00',
    ]);

    AvailabilityRule::factory()->create([
        'provider_id' => $this->provider->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '09:00',
        'end_time' => '18:00',
    ]);

    $windows = $this->service->getAvailableWindows($this->business, $this->provider, $this->monday);

    expect($windows)->toHaveCount(1);
    expect($windows[0]->start->format('H:i'))->toBe('10:00');
    expect($windows[0]->end->format('H:i'))->toBe('16:00');
});

test('business-level full-day block removes all availability', function () {
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
        'end_time' => '17:00',
    ]);

    AvailabilityException::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => null,
        'start_date' => $this->mondayDate,
        'end_date' => $this->mondayDate,
        'type' => ExceptionType::Block,
        'start_time' => null,
        'end_time' => null,
    ]);

    $windows = $this->service->getAvailableWindows($this->business, $this->provider, $this->monday);

    expect($windows)->toBeEmpty();
});

test('business-level partial block splits availability window', function () {
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

    // Business closes 12:00-14:00 for an event
    AvailabilityException::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => null,
        'start_date' => $this->mondayDate,
        'end_date' => $this->mondayDate,
        'type' => ExceptionType::Block,
        'start_time' => '12:00',
        'end_time' => '14:00',
    ]);

    $windows = $this->service->getAvailableWindows($this->business, $this->provider, $this->monday);

    expect($windows)->toHaveCount(2);
    expect($windows[0]->start->format('H:i'))->toBe('09:00');
    expect($windows[0]->end->format('H:i'))->toBe('12:00');
    expect($windows[1]->start->format('H:i'))->toBe('14:00');
    expect($windows[1]->end->format('H:i'))->toBe('18:00');
});

test('provider-level full-day block removes provider availability', function () {
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
        'end_time' => '17:00',
    ]);

    // Provider is sick
    AvailabilityException::factory()->forProvider($this->provider)->create([
        'business_id' => $this->business->id,
        'start_date' => $this->mondayDate,
        'end_date' => $this->mondayDate,
        'type' => ExceptionType::Block,
        'start_time' => null,
        'end_time' => null,
    ]);

    $windows = $this->service->getAvailableWindows($this->business, $this->provider, $this->monday);

    expect($windows)->toBeEmpty();
});

test('provider-level partial block reduces availability', function () {
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
        'end_time' => '17:00',
    ]);

    // Doctor appointment 10:00-11:00
    AvailabilityException::factory()->forProvider($this->provider)->partialDay('10:00', '11:00')->create([
        'business_id' => $this->business->id,
        'start_date' => $this->mondayDate,
        'end_date' => $this->mondayDate,
        'type' => ExceptionType::Block,
    ]);

    $windows = $this->service->getAvailableWindows($this->business, $this->provider, $this->monday);

    expect($windows)->toHaveCount(2);
    expect($windows[0]->start->format('H:i'))->toBe('09:00');
    expect($windows[0]->end->format('H:i'))->toBe('10:00');
    expect($windows[1]->start->format('H:i'))->toBe('11:00');
    expect($windows[1]->end->format('H:i'))->toBe('17:00');
});

test('provider open exception extends availability', function () {
    BusinessHour::factory()->create([
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'open_time' => '08:00',
        'close_time' => '20:00',
    ]);

    AvailabilityRule::factory()->create([
        'provider_id' => $this->provider->id,
        'business_id' => $this->business->id,
        'day_of_week' => DayOfWeek::Monday->value,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    // Provider available 17:00-19:00 extra
    AvailabilityException::factory()->forProvider($this->provider)->open()->create([
        'business_id' => $this->business->id,
        'start_date' => $this->mondayDate,
        'end_date' => $this->mondayDate,
        'start_time' => '17:00',
        'end_time' => '19:00',
    ]);

    $windows = $this->service->getAvailableWindows($this->business, $this->provider, $this->monday);

    expect($windows)->toHaveCount(1);
    expect($windows[0]->start->format('H:i'))->toBe('09:00');
    expect($windows[0]->end->format('H:i'))->toBe('19:00');
});

test('open exception on normally closed day requires both business and provider exceptions', function () {
    // Sunday: business normally closed, provider has no rules

    // Business opens specially on this Sunday
    AvailabilityException::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => null,
        'start_date' => $this->sundayDate,
        'end_date' => $this->sundayDate,
        'type' => ExceptionType::Open,
        'start_time' => '10:00',
        'end_time' => '14:00',
    ]);

    // Provider also available
    AvailabilityException::factory()->forProvider($this->provider)->open()->create([
        'business_id' => $this->business->id,
        'start_date' => $this->sundayDate,
        'end_date' => $this->sundayDate,
        'start_time' => '10:00',
        'end_time' => '14:00',
    ]);

    $windows = $this->service->getAvailableWindows($this->business, $this->provider, $this->sunday);

    expect($windows)->toHaveCount(1);
    expect($windows[0]->start->format('H:i'))->toBe('10:00');
    expect($windows[0]->end->format('H:i'))->toBe('14:00');
});

test('block then re-open composes correctly on same day', function () {
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

    // Full-day block (holiday)
    AvailabilityException::factory()->forProvider($this->provider)->create([
        'business_id' => $this->business->id,
        'start_date' => $this->mondayDate,
        'end_date' => $this->mondayDate,
        'type' => ExceptionType::Block,
        'start_time' => null,
        'end_time' => null,
    ]);

    // But provider works a half day anyway
    AvailabilityException::factory()->forProvider($this->provider)->open()->create([
        'business_id' => $this->business->id,
        'start_date' => $this->mondayDate,
        'end_date' => $this->mondayDate,
        'start_time' => '10:00',
        'end_time' => '14:00',
    ]);

    $windows = $this->service->getAvailableWindows($this->business, $this->provider, $this->monday);

    expect($windows)->toHaveCount(1);
    expect($windows[0]->start->format('H:i'))->toBe('10:00');
    expect($windows[0]->end->format('H:i'))->toBe('14:00');
});

test('multi-day exception applies to each date in range', function () {
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
        'end_time' => '17:00',
    ]);

    // 3-day holiday covering Monday
    AvailabilityException::factory()->create([
        'business_id' => $this->business->id,
        'provider_id' => null,
        'start_date' => '2026-04-12',
        'end_date' => '2026-04-14',
        'type' => ExceptionType::Block,
        'start_time' => null,
        'end_time' => null,
    ]);

    $windows = $this->service->getAvailableWindows($this->business, $this->provider, $this->monday);

    expect($windows)->toBeEmpty();
});
