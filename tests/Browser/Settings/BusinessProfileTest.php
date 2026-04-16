<?php

declare(strict_types=1);

use App\Models\Business;
use Illuminate\Support\Facades\Storage;
use Tests\Browser\Support\BusinessSetup;

// Covers: settings.profile, settings.profile.update, settings.profile.upload-logo,
// settings.profile.slug-check (E2E-5).
//
// HTTP-only tests are ordered before browser tests to avoid RefreshDatabase
// flakiness: running a Playwright `visit()` after several `$this->put()` calls
// can leave the test DB in a non-transacting state, triggering migrate:fresh
// on the next test. Browser tests live at the bottom of each file.

it('persists profile edits and reflects them on reload', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness([
        'name' => 'Old Name',
        'slug' => 'old-name',
    ]);

    $this->actingAs($admin)
        ->put('/dashboard/settings/profile', [
            'name' => 'New Name',
            'slug' => 'old-name',
            'description' => 'New description',
            'phone' => '+41 44 111 22 33',
            'email' => 'new@example.com',
            'address' => 'Neuestrasse 5, 8001 Zurich',
        ])
        ->assertRedirect('/dashboard/settings/profile');

    $business = Business::where('slug', 'old-name')->first();
    expect($business->name)->toBe('New Name')
        ->and($business->description)->toBe('New description')
        ->and($business->phone)->toBe('+41 44 111 22 33')
        ->and($business->email)->toBe('new@example.com')
        ->and($business->address)->toBe('Neuestrasse 5, 8001 Zurich');
});

it('physically deletes the old logo file when the logo is cleared (D-076)', function () {
    Storage::fake('public');

    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness();
    Storage::disk('public')->put('logos/existing.png', 'fake-image-bytes');
    $business->update(['logo' => 'logos/existing.png']);

    // Simulate saving the profile with an empty logo path (reverting to initials).
    $this->actingAs($admin)
        ->put('/dashboard/settings/profile', [
            'name' => $business->name,
            'slug' => $business->slug,
            'logo' => '',
        ])
        ->assertRedirect('/dashboard/settings/profile');

    Storage::disk('public')->assertMissing('logos/existing.png');
    expect($business->fresh()->logo)->toBeNull();
});

it('reports slug availability for own, free, taken, and reserved slugs', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness([
        'slug' => 'mine',
    ]);
    Business::factory()->create(['slug' => 'taken-slug']);

    $this->actingAs($admin);

    // Own slug → available (self-match).
    $this->post('/dashboard/settings/profile/slug-check', ['slug' => 'mine'])
        ->assertOk()
        ->assertJson(['available' => true]);

    // Free slug → available.
    $this->post('/dashboard/settings/profile/slug-check', ['slug' => 'something-fresh'])
        ->assertOk()
        ->assertJson(['available' => true]);

    // Taken by another business → unavailable.
    $this->post('/dashboard/settings/profile/slug-check', ['slug' => 'taken-slug'])
        ->assertOk()
        ->assertJson(['available' => false]);

    // Reserved → unavailable.
    $this->post('/dashboard/settings/profile/slug-check', ['slug' => 'dashboard'])
        ->assertOk()
        ->assertJson(['available' => false]);
});

it('denies staff members with a 403 (admin-only)', function () {
    ['staff' => $staffCollection] = BusinessSetup::createBusinessWithStaff(
        1,
        ['onboarding_step' => 5, 'onboarding_completed_at' => now()],
    );
    $staff = $staffCollection->first();

    $this->actingAs($staff)->get('/dashboard/settings/profile')->assertForbidden();
});

it('changes the slug and makes the old public URL 404 while the new one loads', function () {
    ['business' => $business, 'admin' => $admin] = BusinessSetup::createLaunchedBusiness([
        'slug' => 'old-slug',
    ]);

    // Ensure the new slug is not taken by anyone else.
    Business::factory()->create(['slug' => 'placeholder-biz']);

    $this->actingAs($admin)
        ->put('/dashboard/settings/profile', [
            'name' => $business->name,
            'slug' => 'brand-new-slug',
        ])
        ->assertRedirect('/dashboard/settings/profile');

    expect($business->fresh()->slug)->toBe('brand-new-slug');

    $oldPage = visit('/old-slug');
    $oldPage->assertSee('404');

    $newPage = visit('/brand-new-slug');
    $newPage->assertPathIs('/brand-new-slug')
        ->assertNoJavaScriptErrors();
});

it('renders the profile settings page with business data', function () {
    ['admin' => $admin] = BusinessSetup::createLaunchedBusiness([
        'name' => 'Salone Maria',
        'slug' => 'salone-maria',
        'description' => 'A welcoming studio.',
        'phone' => '+41 44 000 00 00',
        'email' => 'hello@salone.example',
        'address' => 'Bahnhofstrasse 1, 8001 Zurich',
    ]);

    $this->actingAs($admin);

    $page = visit('/dashboard/settings/profile');
    $page->assertPathIs('/dashboard/settings/profile')
        ->assertSee('Business profile')
        ->assertValue('input[name="name"]', 'Salone Maria')
        ->assertValue('input[name="slug"]', 'salone-maria')
        ->assertNoJavaScriptErrors();
});

it('denies guests and redirects them to /login', function () {
    $page = visit('/dashboard/settings/profile');

    $page->assertPathIs('/login')
        ->assertNoJavaScriptErrors();
});
