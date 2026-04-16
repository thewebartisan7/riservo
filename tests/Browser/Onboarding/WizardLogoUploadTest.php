<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Browser\Support\BusinessSetup;

// Covers: POST /onboarding/logo-upload — instant logo upload on Step 1
// (D-042, E2E-2). The endpoint is consumed by Step 1's useHttp hook; we drive
// it via HTTP with a real UploadedFile and also exercise the Step 1 page.

beforeEach(function () {
    Storage::fake('public');
});

it('renders the logo upload control on step 1', function () {
    ['admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 1,
    ]);

    $this->actingAs($admin);

    visit('/onboarding/step/1')
        ->assertPathIs('/onboarding/step/1')
        ->assertSee('Upload')
        ->assertSee('Square works best. JPG, PNG or WebP, up to 2MB.')
        ->assertNoJavaScriptErrors();
});

it('accepts a valid image and persists the path on the business', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 1,
    ]);

    $file = UploadedFile::fake()->image('logo.jpg', 256, 256);

    $this->actingAs($admin)
        ->post('/onboarding/logo-upload', ['logo' => $file])
        ->assertOk()
        ->assertJsonStructure(['path', 'url']);

    $business->refresh();
    expect($business->logo)->not->toBeNull();
    Storage::disk('public')->assertExists($business->logo);
});

it('rejects a non-image attachment (PDF) with a 422 from the server', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createBusinessWithAdmin([
        'onboarding_step' => 1,
    ]);

    $file = UploadedFile::fake()->create('document.pdf', 100);

    $this->actingAs($admin)
        ->postJson('/onboarding/logo-upload', ['logo' => $file])
        ->assertUnprocessable();

    $business->refresh();
    expect($business->logo)->toBeNull();
});
