<?php

declare(strict_types=1);

use App\Models\Business;
use Tests\Browser\Support\BusinessSetup;

// Covers: POST /onboarding/slug-check — live availability check on Step 1
// (E2E-2). The endpoint is consumed by Step 1's useHttp hook; these tests
// drive the endpoint directly as the browser would, and also exercise the
// Step 1 page render via visit().

it('renders the slug input with the current business slug on step 1', function () {
    ['admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'slug' => 'my-current-slug',
        'onboarding_step' => 1,
    ]);

    $this->actingAs($admin);

    visit('/onboarding/step/1')
        ->assertPathIs('/onboarding/step/1')
        ->assertValue('slug', 'my-current-slug')
        ->assertSee('Lowercase letters, numbers, and dashes only')
        ->assertNoJavaScriptErrors();
});

it('returns available=true for a fresh slug', function () {
    ['admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'slug' => 'my-current-slug',
        'onboarding_step' => 1,
    ]);

    $this->actingAs($admin)
        ->postJson('/onboarding/slug-check', ['slug' => 'fresh-available-slug'])
        ->assertOk()
        ->assertJson(['available' => true]);
});

it('returns available=false for a slug taken by another business', function () {
    Business::factory()->create(['slug' => 'taken-by-someone-else']);

    ['admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'slug' => 'my-current-slug',
        'onboarding_step' => 1,
    ]);

    $this->actingAs($admin)
        ->postJson('/onboarding/slug-check', ['slug' => 'taken-by-someone-else'])
        ->assertOk()
        ->assertJson(['available' => false]);
});

it('returns available=false for a reserved system slug', function () {
    ['admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 1,
    ]);

    $this->actingAs($admin)
        ->postJson('/onboarding/slug-check', ['slug' => 'dashboard'])
        ->assertOk()
        ->assertJson(['available' => false]);
});

it('treats the business\'s own current slug as available (self-match)', function () {
    ['admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'slug' => 'self-match-demo',
        'onboarding_step' => 1,
    ]);

    $this->actingAs($admin)
        ->postJson('/onboarding/slug-check', ['slug' => 'self-match-demo'])
        ->assertOk()
        ->assertJson(['available' => true]);
});
