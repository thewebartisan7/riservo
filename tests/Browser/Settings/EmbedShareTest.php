<?php

declare(strict_types=1);

use Tests\Browser\Support\BusinessSetup;

// Covers: settings.embed (E2E-5).
//
// HTTP-only tests precede browser tests to avoid RefreshDatabase flakiness
// in the Pest Browser suite — see BookingSettingsTest.php for details.

it('denies staff members with a 403', function () {
    ['staff' => $staffCollection] = BusinessSetup::createBusinessWithStaff(
        1,
        ['onboarding_step' => 5, 'onboarding_completed_at' => now()],
    );
    $staff = $staffCollection->first();

    $this->actingAs($staff)->get('/dashboard/settings/embed')->assertForbidden();
});

it('renders the embed & share page with the iframe and popup snippets', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness([
        'slug' => 'share-studio',
    ]);

    $this->actingAs($admin);

    $page = visit('/dashboard/settings/embed');
    $page->assertPathIs('/dashboard/settings/embed')
        ->assertSee('Embed & share')
        ->assertSee('share-studio')
        ->assertSee('<iframe')
        ->assertSee('data-riservo-open')
        ->assertSee('embed.js')
        ->assertNoJavaScriptErrors();
});

it('renders the direct link for the current slug', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness([
        'slug' => 'link-me',
    ]);

    $this->actingAs($admin);

    $page = visit('/dashboard/settings/embed');
    $page->assertSee('link-me')
        ->assertNoJavaScriptErrors();
});

it('renders a per-service preview selector when the business has services', function () {
    ['service' => $service, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();
    $service->update(['name' => 'Manicure Deluxe']);

    $this->actingAs($admin);

    $page = visit('/dashboard/settings/embed');
    $page->assertSee('Pre-filter by service')
        ->assertNoJavaScriptErrors();
});

it('renders the live preview iframe', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin);

    $page = visit('/dashboard/settings/embed');
    $page->assertSee('Live preview')
        ->assertPresent('iframe[title="Booking form preview"]');
});
