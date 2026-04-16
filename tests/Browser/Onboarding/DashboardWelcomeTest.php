<?php

declare(strict_types=1);

use Tests\Browser\Support\BusinessSetup;

// Covers: GET /dashboard/welcome — post-wizard landing page (E2E-2).

it('renders the business name, public URL, and dashboard CTA for a just-launched admin', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness([
        'name' => 'Studio Alba',
        'slug' => 'studio-alba',
    ]);

    $this->actingAs($admin);

    visit('/dashboard/welcome')
        ->assertPathIs('/dashboard/welcome')
        ->assertSee('Studio Alba')
        ->assertSee('is live.')
        ->assertSee('studio-alba')
        ->assertSee('Your booking page')
        ->assertSee('Copy link')
        ->assertSee('Open your dashboard')
        ->assertNoJavaScriptErrors();
});

it('navigates to /dashboard when the admin clicks "Open your dashboard"', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin);

    visit('/dashboard/welcome')
        ->click('Open your dashboard')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
});

it('shows next-step CTAs linking to settings pages', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness();

    $this->actingAs($admin);

    visit('/dashboard/welcome')
        ->assertSee('Shape your services')
        ->assertSee('Invite your team')
        ->assertSee('Tune your reminders')
        ->assertNoJavaScriptErrors();
});
