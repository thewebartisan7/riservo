<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Public-booking-flow helpers. Implemented by E2E-3.
 *
 * See docs/roadmaps/ROADMAP-E2E.md → Session E2E-3.
 *
 * The funnel lives at `resources/js/pages/booking/show.tsx`. It is a single
 * Inertia page (D-043) whose step is driven by client-side state. JSON endpoints
 * (`booking.providers`, `booking.available-dates`, `booking.slots`,
 * `booking.store`) are called via `useHttp`.
 *
 * These helpers drive the UI for the standard happy path — pick the first
 * available service, (optionally) pick "Any specialist", pick the first day in
 * the calendar that opens, pick the first available slot, fill the customer
 * form, confirm. They return the cancellation token surfaced on the confirmation
 * screen (read back from the database by cancellation_token, keyed off the
 * created booking for the given customer email — the token is not embedded in
 * the visible DOM).
 */
final class BookingFlowHelper
{
    /**
     * Drive the public booking funnel as a guest and return the management
     * token of the resulting booking.
     *
     * $customerDetails accepts `name`, `email`, `phone`, `notes` overrides. Any
     * key that is missing falls back to the stock fixture below.
     *
     * @param  array<string, string>  $customerDetails
     */
    public static function bookAsGuest(mixed $page, Business $business, Service $service, array $customerDetails = []): string
    {
        $details = array_merge([
            'name' => 'Jane Doe',
            'email' => 'jane.'.uniqid().'@example.com',
            'phone' => '+41 79 123 45 67',
            'notes' => '',
        ], $customerDetails);

        self::driveFunnel($page, $business, $service, $details, allowProviderChoice: $business->allow_provider_choice);

        return self::resolveToken($details['email']);
    }

    /**
     * Drive the public booking funnel as a logged-in Customer. The customer
     * details come straight from the Customer row; the customer form is
     * expected to be pre-filled server-side via `customerPrefill` on the
     * Inertia props.
     */
    public static function bookAsRegistered(mixed $page, Business $business, Service $service, Customer $customer): string
    {
        $details = [
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone ?? '+41 79 123 45 67',
            'notes' => '',
        ];

        self::driveFunnel($page, $business, $service, $details, allowProviderChoice: $business->allow_provider_choice, prefilled: true);

        return self::resolveToken($customer->email);
    }

    /**
     * Shared funnel walker. Starts on the landing page and ends on the
     * confirmation step.
     *
     * @param  array<string, string>  $details
     */
    private static function driveFunnel(mixed $page, Business $business, Service $service, array $details, bool $allowProviderChoice, bool $prefilled = false): void
    {
        $page->navigate('/'.$business->slug);

        // Service step.
        $page->assertSee($service->name)
            ->click($service->name);

        // Provider step — only when allow_provider_choice is true.
        if ($allowProviderChoice) {
            $page->assertSee('Any specialist')
                ->click('Any specialist');
        }

        // Date/time step — click the next real Monday (in business timezone). The browser's
        // wall clock drives the client-side "today" threshold (see date-time-picker.tsx), so
        // the clicked day must be today or a future day in the browser's reckoning. We use
        // the real `now()` here on purpose — `travelTo()` freezes only PHP, not the browser.
        $page->assertSee('When works for you?');

        $targetDate = CarbonImmutable::now($business->timezone);
        if ($targetDate->dayOfWeekIso !== 1) {
            $targetDate = $targetDate->next(Carbon::MONDAY);
        }
        self::selectDateAndTime($page, $targetDate);

        // Customer details step — fill only when not prefilled (the form keeps prefilled values untouched).
        $page->assertSee('Just a few details');
        if (! $prefilled) {
            $page->type('name', $details['name'])
                ->type('email', $details['email'])
                ->type('phone', $details['phone']);
        }

        // Continue and Confirm buttons wrap their text inside <Display> — press() text match
        // is unreliable against nested elements, so click via a text-content script.
        $page->script("Array.from(document.querySelectorAll('button[type=\"submit\"]')).find(b => (b.textContent || '').includes('Continue'))?.click();");

        // Summary step — confirm.
        $page->assertSee('Everything in order?');
        $page->script("Array.from(document.querySelectorAll('button')).find(b => (b.textContent || '').includes('Confirm booking'))?.click();");

        // Confirmation.
        $page->assertSee($business->name);
    }

    public static function selectDateAndTime(mixed $page, CarbonImmutable $targetDate, string $time = '09:00'): void
    {
        $browserNow = (array) $page->script(<<<'JS'
(() => {
    const now = new Date();

    return {
        year: now.getFullYear(),
        month: now.getMonth() + 1,
    };
})()
JS);

        $monthsToAdvance = (((int) $targetDate->format('Y') - (int) $browserNow['year']) * 12)
            + ((int) $targetDate->format('n') - (int) $browserNow['month']);

        for ($i = 0; $i < max(0, $monthsToAdvance); $i++) {
            $page->click('button[aria-label="Next month"]');
        }

        $day = (int) $targetDate->format('d');

        // travelTo() freezes PHP only; navigate by full month before clicking the seeded day.
        $page->assertScript(<<<JS
(() => {
    const button = Array.from(document.querySelectorAll('button.tabular-nums'))
        .find((element) => element.textContent.trim() === '{$day}');

    return !!button && !button.disabled;
})()
JS);
        $page->script("Array.from(document.querySelectorAll('button.tabular-nums')).find(b => b.textContent.trim() === '{$day}' && !b.disabled)?.click();");

        // Wait for a slot button to appear (assertSee implicitly waits) and click it.
        // The slot button text like '09:00' contains a colon, which confuses CSS parsers,
        // so click via a script by text content instead of the click() helper.
        $page->assertSee($time);
        $page->script("Array.from(document.querySelectorAll('button')).find(b => b.textContent.trim() === '{$time}')?.click();");
    }

    private static function resolveToken(string $email): string
    {
        $customer = Customer::where('email', $email)->firstOrFail();
        $booking = $customer->bookings()->latest('id')->firstOrFail();

        return $booking->cancellation_token;
    }
}
