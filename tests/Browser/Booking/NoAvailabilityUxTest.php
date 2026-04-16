<?php

declare(strict_types=1);

use App\Models\AvailabilityException;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\BusinessSetup;

// Covers: D-038 public slot UX — no-availability messaging on the date step.

beforeEach(function () {
    $this->today = CarbonImmutable::now('Europe/Zurich');
    $this->travelTo($this->today->setTime(7, 30));
});

it('shows the no-availability message when the whole month is blocked and the "Try next" button is present', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    // Block the current month (browser defaults to `new Date()` for the view month).
    $monthStart = $this->today->startOfMonth();
    $monthEnd = $this->today->endOfMonth();

    AvailabilityException::create([
        'business_id' => $business->id,
        'provider_id' => null,
        'start_date' => $monthStart->format('Y-m-d'),
        'end_date' => $monthEnd->format('Y-m-d'),
        'start_time' => null,
        'end_time' => null,
        'type' => 'block',
        'reason' => 'Closed for refurbishment',
    ]);

    $page = visit('/'.$business->slug);
    $page->click($service->name);

    // Navigate into the provider step's "Any specialist" when provider choice is on.
    if ($business->allow_provider_choice) {
        $page->click('Any specialist');
    }

    $page->assertSee('When works for you?')
        ->assertSee('No openings this month.')
        ->assertSee('Try next')
        ->assertNoJavaScriptErrors();
});

it('greys out days with no availability (they are rendered but disabled)', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    // Block the next real Monday in the current month so the calendar cell must exist but be disabled.
    $nextMonday = $this->today;
    if ($nextMonday->dayOfWeekIso !== 1) {
        $nextMonday = $nextMonday->next(Carbon\Carbon::MONDAY);
    }
    // If the next Monday slipped into next month, fall back to any weekday in this month.
    if ($nextMonday->format('Y-m') !== $this->today->format('Y-m')) {
        $nextMonday = $this->today->addDay();
    }
    $blockDay = (int) $nextMonday->format('d');

    AvailabilityException::create([
        'business_id' => $business->id,
        'provider_id' => null,
        'start_date' => $nextMonday->format('Y-m-d'),
        'end_date' => $nextMonday->format('Y-m-d'),
        'start_time' => null,
        'end_time' => null,
        'type' => 'block',
        'reason' => 'Holiday',
    ]);

    $page = visit('/'.$business->slug);
    $page->click($service->name);
    if ($business->allow_provider_choice) {
        $page->click('Any specialist');
    }

    $page->assertSee('When works for you?');

    // The blocked cell exists in the DOM but is disabled — calendar days are not hidden.
    $disabled = $page->script("(() => { const cells = Array.from(document.querySelectorAll('button.tabular-nums')); const blocked = cells.find(b => b.textContent.trim() === '{$blockDay}' && b.disabled); return !!blocked; })()");

    expect($disabled)->toBeTruthy();
});
