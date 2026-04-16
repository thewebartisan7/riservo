<?php

declare(strict_types=1);

use App\Models\BusinessHour;
use Tests\Browser\Support\BusinessSetup;

// Covers: settings.hours, settings.hours.update (E2E-5).
//
// HTTP-only tests precede the browser test to avoid RefreshDatabase flakiness
// in the Pest Browser suite — see BookingSettingsTest.php for details.

it('disables a day by persisting a schedule without its row', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    // Turn off Friday (5). Keep Mon–Thu open; weekends already closed.
    $hours = collect(range(1, 7))->map(function (int $day): array {
        if ($day >= 1 && $day <= 4) {
            return [
                'day_of_week' => $day,
                'enabled' => true,
                'windows' => [['open_time' => '09:00', 'close_time' => '18:00']],
            ];
        }

        return ['day_of_week' => $day, 'enabled' => false, 'windows' => []];
    })->all();

    $this->actingAs($admin)
        ->put('/dashboard/settings/hours', ['hours' => $hours])
        ->assertRedirect('/dashboard/settings/hours');

    expect(BusinessHour::where('business_id', $business->id)->where('day_of_week', 5)->count())->toBe(0);
    expect(BusinessHour::where('business_id', $business->id)->where('day_of_week', 1)->count())->toBe(1);
});

it('accepts a second time window on the same day (morning + afternoon)', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $hours = collect(range(1, 7))->map(function (int $day): array {
        if ($day === 1) {
            return [
                'day_of_week' => 1,
                'enabled' => true,
                'windows' => [
                    ['open_time' => '09:00', 'close_time' => '12:00'],
                    ['open_time' => '14:00', 'close_time' => '18:00'],
                ],
            ];
        }

        if ($day >= 2 && $day <= 5) {
            return [
                'day_of_week' => $day,
                'enabled' => true,
                'windows' => [['open_time' => '09:00', 'close_time' => '18:00']],
            ];
        }

        return ['day_of_week' => $day, 'enabled' => false, 'windows' => []];
    })->all();

    $this->actingAs($admin)
        ->put('/dashboard/settings/hours', ['hours' => $hours])
        ->assertRedirect('/dashboard/settings/hours');

    $mondayHours = BusinessHour::where('business_id', $business->id)
        ->where('day_of_week', 1)
        ->orderBy('open_time')
        ->get();

    expect($mondayHours)->toHaveCount(2);
});

it('rejects a window whose close time is before the open time', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $hours = collect(range(1, 7))->map(function (int $day): array {
        if ($day === 1) {
            return [
                'day_of_week' => 1,
                'enabled' => true,
                'windows' => [['open_time' => '18:00', 'close_time' => '09:00']],
            ];
        }

        return ['day_of_week' => $day, 'enabled' => false, 'windows' => []];
    })->all();

    $this->actingAs($admin)
        ->put('/dashboard/settings/hours', ['hours' => $hours])
        ->assertSessionHasErrors();
});

it('denies staff members with a 403', function () {
    ['staff' => $staffCollection] = BusinessSetup::createBusinessWithStaff(
        1,
        ['onboarding_step' => 5, 'onboarding_completed_at' => now()],
    );
    $staff = $staffCollection->first();

    $this->actingAs($staff)->get('/dashboard/settings/hours')->assertForbidden();
});

it('renders the working hours page with seven day rows', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin);

    $page = visit('/dashboard/settings/hours');
    $page->assertPathIs('/dashboard/settings/hours')
        ->assertSee('Working hours')
        ->assertSee('Monday')
        ->assertSee('Saturday')
        ->assertSee('Sunday')
        ->assertNoJavaScriptErrors();
});
