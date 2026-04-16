<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->business = Business::factory()->create([
        'name' => 'Test Business',
        'slug' => 'test-business',
        'onboarding_step' => 1,
    ]);
    attachAdmin($this->business, $this->user);
});

test('step 1 page renders with pre-filled business data', function () {
    $response = $this->actingAs($this->user)->get('/onboarding/step/1');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('onboarding/step-1')
        ->has('business')
        ->where('business.name', 'Test Business')
        ->where('business.slug', 'test-business')
    );
});

test('step 1 updates business profile', function () {
    $response = $this->actingAs($this->user)->post('/onboarding/step/1', [
        'name' => 'Updated Business',
        'slug' => 'updated-business',
        'description' => 'A great business',
        'phone' => '+41 79 123 4567',
        'email' => 'info@business.ch',
        'address' => 'Main Street 1, 8000 Zurich',
    ]);

    $response->assertRedirect('/onboarding/step/2');

    $this->business->refresh();
    expect($this->business->name)->toBe('Updated Business');
    expect($this->business->slug)->toBe('updated-business');
    expect($this->business->description)->toBe('A great business');
    expect($this->business->phone)->toBe('+41 79 123 4567');
    expect($this->business->onboarding_step)->toBe(2);
});

test('step 1 validates required fields', function () {
    $response = $this->actingAs($this->user)->post('/onboarding/step/1', [
        'name' => '',
        'slug' => '',
    ]);

    $response->assertSessionHasErrors(['name', 'slug']);
});

test('step 1 validates slug format', function () {
    $response = $this->actingAs($this->user)->post('/onboarding/step/1', [
        'name' => 'Test',
        'slug' => 'INVALID SLUG!',
    ]);

    $response->assertSessionHasErrors(['slug']);
});

test('step 1 validates slug uniqueness', function () {
    Business::factory()->create(['slug' => 'taken-slug']);

    $response = $this->actingAs($this->user)->post('/onboarding/step/1', [
        'name' => 'Test',
        'slug' => 'taken-slug',
    ]);

    $response->assertSessionHasErrors(['slug']);
});

test('step 1 allows own slug', function () {
    $response = $this->actingAs($this->user)->post('/onboarding/step/1', [
        'name' => 'Test Business',
        'slug' => 'test-business',
    ]);

    $response->assertRedirect('/onboarding/step/2');
});

test('step 1 rejects reserved slug', function () {
    $response = $this->actingAs($this->user)->post('/onboarding/step/1', [
        'name' => 'Test',
        'slug' => 'dashboard',
    ]);

    $response->assertSessionHasErrors(['slug']);
});

test('logo upload stores file and updates business', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('logo.jpg');

    $response = $this->actingAs($this->user)->post('/onboarding/logo-upload', [
        'logo' => $file,
    ]);

    $response->assertSuccessful();
    $response->assertJsonStructure(['path', 'url']);

    $this->business->refresh();
    expect($this->business->logo)->not->toBeNull();
    Storage::disk('public')->assertExists($this->business->logo);
});

test('storing profile with empty logo deletes the existing file and stores null', function () {
    Storage::fake('public');

    $path = UploadedFile::fake()->image('existing.jpg')->store('logos', 'public');
    $this->business->update(['logo' => $path]);
    Storage::disk('public')->assertExists($path);

    $response = $this->actingAs($this->user)->post('/onboarding/step/1', [
        'name' => 'Test Business',
        'slug' => 'test-business',
        'logo' => '',
    ]);

    $response->assertRedirect('/onboarding/step/2');

    $this->business->refresh();
    expect($this->business->logo)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('logo upload validates file type', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->create('document.pdf', 100);

    $response = $this->actingAs($this->user)->postJson('/onboarding/logo-upload', [
        'logo' => $file,
    ]);

    $response->assertUnprocessable();
});
