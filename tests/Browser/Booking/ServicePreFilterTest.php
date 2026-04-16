<?php

declare(strict_types=1);

use Tests\Browser\Support\BusinessSetup;

// Covers: GET /{slug}/{serviceSlug?} (canonical path pre-filter per D-070) — booking.show.

it('auto-selects the service and opens the provider step when provider choice is enabled', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    // allow_provider_choice defaults to true on the factory — provider step should follow service pre-select.
    $service->update(['slug' => 'haircut']);

    $page = visit('/'.$business->slug.'/haircut');

    // Provider step heading from provider-picker.tsx.
    $page->assertSee('Who would you like to see?')
        ->assertSee('Any specialist')
        ->assertDontSee('Choose a service')
        ->assertNoJavaScriptErrors();
});

it('auto-selects the service and skips to the date/time step when provider choice is disabled', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();
    $business->update(['allow_provider_choice' => false]);
    $service->update(['slug' => 'haircut']);

    $page = visit('/'.$business->slug.'/haircut');

    // Date/time step heading from date-time-picker.tsx.
    $page->assertSee('When works for you?')
        ->assertDontSee('Choose a service')
        ->assertDontSee('Who would you like to see?')
        ->assertNoJavaScriptErrors();
});

it('falls through to the service picker when the slug is unknown (D-070)', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();
    $service->update(['slug' => 'haircut']);

    $page = visit('/'.$business->slug.'/i-do-not-exist');

    // Service step is shown (unknown slug is silently ignored per D-070) — page still renders.
    $page->assertSee('Choose a service')
        ->assertSee($service->name)
        ->assertNoJavaScriptErrors();
});
