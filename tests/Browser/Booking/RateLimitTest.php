<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Browser\Support\BusinessSetup;

// Covers: booking-create (5/min/IP) and booking-api (60/min/IP) limiters
// declared in AppServiceProvider::boot().

beforeEach(function () {
    $next = CarbonImmutable::now('Europe/Zurich');
    if ($next->dayOfWeekIso !== 1) {
        $next = $next->next(Carbon\Carbon::MONDAY);
    }
    $this->targetMonday = $next;
    $this->travelTo($next->setTime(7, 30));
    RateLimiter::clear('booking-create:127.0.0.1');
    RateLimiter::clear('booking-api:127.0.0.1');
});

it('returns 429 after 5 booking-create submissions within a minute', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    $payload = [
        'service_id' => $service->id,
        'provider_id' => null,
        'date' => $this->targetMonday->format('Y-m-d'),
        'time' => '10:00',
        'name' => 'Rate Limit',
        'email' => 'rate@example.com',
        'phone' => '+41 79 000 00 00',
        'website' => '',
    ];

    // First 5 requests are allowed by the `booking-create` limiter (5/min/IP).
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/booking/'.$business->slug.'/book', array_merge($payload, [
            'email' => 'rate'.$i.'@example.com',
        ]));
    }

    // 6th request is throttled regardless of the outcome of the first 5.
    $response = $this->postJson('/booking/'.$business->slug.'/book', array_merge($payload, [
        'email' => 'rate-6@example.com',
    ]));

    expect($response->status())->toBe(429);
});

it('returns 429 on the booking-api endpoints after 60 requests/min/IP', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    $date = $this->targetMonday->format('Y-m-d');
    // First 60 GETs are allowed. Use the slots endpoint as a representative booking-api route.
    for ($i = 0; $i < 60; $i++) {
        $this->get('/booking/'.$business->slug.'/slots?service_id='.$service->id.'&date='.$date);
    }

    $response = $this->get('/booking/'.$business->slug.'/slots?service_id='.$service->id.'&date='.$date);

    expect($response->status())->toBe(429);
});
