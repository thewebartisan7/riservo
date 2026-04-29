<?php

declare(strict_types=1);

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\BookingFlowHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: D-045 honeypot field `website` rejects with 422 when filled.
// The field is off-screen (position -9999px) so a real user never fills it.

beforeEach(function () {
    // Pick a full target date because travelTo() does not move the browser's calendar month.
    $next = CarbonImmutable::now('Europe/Zurich');
    if ($next->dayOfWeekIso !== 1) {
        $next = $next->next(Carbon\Carbon::MONDAY);
    }
    $this->targetDate = $next;
    $this->travelTo($next->setTime(7, 30));
});

it('does not create a booking when the honeypot field is filled', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    $page = visit('/'.$business->slug);
    $page->click($service->name);
    if ($business->allow_provider_choice) {
        $page->click('Any specialist');
    }

    BookingFlowHelper::selectDateAndTime($page, $this->targetDate);

    $page->assertSee('Just a few details')
        ->type('name', 'Bot McBotface')
        ->type('email', 'bot@example.com')
        ->type('phone', '+41 79 000 00 00');

    // Fill the off-screen honeypot input by ID (`booking-hp`, see customer-form.tsx).
    $page->script("document.getElementById('booking-hp').value = 'https://spam.com';");

    // Both Continue and Confirm buttons wrap their text inside <Display> — click via script.
    $page->script("Array.from(document.querySelectorAll('button[type=\"submit\"]')).find(b => (b.textContent || '').includes('Continue'))?.click();");
    $page->assertSee('Everything in order?');
    $page->script("Array.from(document.querySelectorAll('button')).find(b => (b.textContent || '').includes('Confirm booking'))?.click();");

    // Server rejects with 422 (D-045). No booking is created.
    expect(Booking::count())->toBe(0);
});
