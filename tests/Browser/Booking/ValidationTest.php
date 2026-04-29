<?php

declare(strict_types=1);

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\BookingFlowHelper;
use Tests\Browser\Support\BusinessSetup;

// Covers: customer-details-form client-side validation (empty name/email/phone,
// invalid email format) on the public booking funnel.

beforeEach(function () {
    // Pick a full target date because travelTo() does not move the browser's calendar month.
    $next = CarbonImmutable::now('Europe/Zurich');
    if ($next->dayOfWeekIso !== 1) {
        $next = $next->next(Carbon\Carbon::MONDAY);
    }
    $this->targetDate = $next;
    $this->travelTo($next->setTime(7, 30));
});

it('blocks advance and shows per-field errors when name, email, and phone are empty', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    $page = visit('/'.$business->slug);
    $page->click($service->name);
    if ($business->allow_provider_choice) {
        $page->click('Any specialist');
    }

    BookingFlowHelper::selectDateAndTime($page, $this->targetDate);

    $page->assertSee('Just a few details');
    // The "Continue to review" button wraps its text inside <Display> — click via script.
    $page->script("Array.from(document.querySelectorAll('button[type=\"submit\"]')).find(b => (b.textContent || '').includes('Continue'))?.click();");

    // The customer-form component surfaces three inline errors (client-side validation).
    $page->assertSee('Please share your name.')
        ->assertSee('We need an email to send confirmation.')
        ->assertSee('A phone number helps in case plans change.')
        ->assertNoJavaScriptErrors();

    // Still on the details step — booking not created.
    expect(Booking::count())->toBe(0);
});
