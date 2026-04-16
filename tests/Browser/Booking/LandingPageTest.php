<?php

declare(strict_types=1);

use App\Models\Business;
use App\Models\Service;
use Tests\Browser\Support\BusinessSetup;

// Covers: GET /{slug} — booking.show — booking/show.tsx (E2E-3).

it('shows the business name, description, and services for a launched business', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness([
        'name' => 'Salone Mario',
        'description' => 'A friendly haircut studio.',
    ]);

    $page = visit('/'.$business->slug);

    $page->assertSee('Salone Mario')
        ->assertSee('A friendly haircut studio.')
        ->assertSee($service->name)
        ->assertSee('Choose a service')
        ->assertNoJavaScriptErrors();
});

it('hides inactive services from the public landing page', function () {
    ['business' => $business, 'service' => $active, 'provider' => $provider] = BusinessSetup::createLaunchedBusiness();

    $inactive = Service::factory()
        ->for($business)
        ->inactive()
        ->create(['name' => 'Hidden Service']);
    $provider->services()->attach($inactive);

    $page = visit('/'.$business->slug);

    $page->assertSee($active->name)
        ->assertDontSee('Hidden Service')
        ->assertNoJavaScriptErrors();
});

it('hides services with zero providers', function () {
    ['business' => $business, 'service' => $staffed] = BusinessSetup::createLaunchedBusiness();

    // Create a second service with no providers — it must be hidden (D-062).
    Service::factory()->for($business)->create(['name' => 'Orphaned Service']);

    $page = visit('/'.$business->slug);

    $page->assertSee($staffed->name)
        ->assertDontSee('Orphaned Service')
        ->assertNoJavaScriptErrors();
});

it('returns a 404-style page for an unknown slug', function () {
    $page = visit('/does-not-exist-slug');

    // Framework default abort(404) page. Any of these strings indicates the not-found response.
    $page->assertSee('Not Found');
});

it('returns 404 for a business that has not finished onboarding', function () {
    // Onboarding incomplete — `onboarding_completed_at` is null via default factory state.
    $business = Business::factory()->create();

    $page = visit('/'.$business->slug);

    $page->assertSee('Not Found');
});
