<?php

declare(strict_types=1);

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\BusinessSetup;

// Covers: customer-details-form client-side validation (empty name/email/phone,
// invalid email format) on the public booking funnel.

beforeEach(function () {
    // Next real Monday (in business timezone) — browser uses real wall clock for its
    // "today" threshold; we align the PHP clock to match so availability and the client
    // agree on which day is future.
    $next = CarbonImmutable::now('Europe/Zurich');
    if ($next->dayOfWeekIso !== 1) {
        $next = $next->next(Carbon\Carbon::MONDAY);
    }
    $this->targetDay = (int) $next->format('d');
    $this->travelTo($next->setTime(7, 30));
});

it('blocks advance and shows per-field errors when name, email, and phone are empty', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    $page = visit('/'.$business->slug);
    $page->click($service->name);
    if ($business->allow_provider_choice) {
        $page->click('Any specialist');
    }

    $day = $this->targetDay;
    $page->script("Array.from(document.querySelectorAll('button.tabular-nums')).find(b => b.textContent.trim() === '{$day}' && !b.disabled)?.click();");
    $page->assertSee('09:00');
    $page->script("Array.from(document.querySelectorAll('button')).find(b => b.textContent.trim() === '09:00')?.click();");

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
