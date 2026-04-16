<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create([
        'name' => 'Test Salon',
        'slug' => 'test-salon',
    ]);
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);
});

test('admin can view profile settings', function () {
    $this->actingAs($this->admin)
        ->get('/dashboard/settings/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/settings/profile')
            ->has('business')
            ->where('business.name', 'Test Salon')
            ->where('business.slug', 'test-salon')
        );
});

test('admin can update profile', function () {
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/profile', [
            'name' => 'Updated Salon',
            'slug' => 'updated-salon',
            'description' => 'A great salon',
            'phone' => '+41 79 123 45 67',
            'email' => 'info@salon.ch',
            'address' => 'Bahnhofstrasse 1, 8001 Zurich',
        ])
        ->assertRedirect('/dashboard/settings/profile');

    $this->business->refresh();
    expect($this->business->name)->toBe('Updated Salon');
    expect($this->business->slug)->toBe('updated-salon');
    expect($this->business->description)->toBe('A great salon');
});

test('slug validation rejects reserved slugs', function () {
    $this->actingAs($this->admin)
        ->put('/dashboard/settings/profile', [
            'name' => 'Test',
            'slug' => 'dashboard',
        ])
        ->assertSessionHasErrors('slug');
});

test('slug validation rejects taken slugs', function () {
    Business::factory()->create(['slug' => 'taken-slug']);

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/profile', [
            'name' => 'Test',
            'slug' => 'taken-slug',
        ])
        ->assertSessionHasErrors('slug');
});

test('slug check endpoint returns availability', function () {
    $this->actingAs($this->admin)
        ->post('/dashboard/settings/profile/slug-check', ['slug' => 'test-salon'])
        ->assertOk()
        ->assertJson(['available' => true]);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/profile/slug-check', ['slug' => 'dashboard'])
        ->assertOk()
        ->assertJson(['available' => false]);
});

test('admin can upload logo', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/profile/logo', [
            'logo' => UploadedFile::fake()->image('logo.jpg'),
        ])
        ->assertOk()
        ->assertJsonStructure(['path', 'url']);

    expect($this->business->fresh()->logo)->not->toBeNull();
    Storage::disk('public')->assertExists($this->business->fresh()->logo);
});

test('updating profile with empty logo deletes the existing file and stores null', function () {
    Storage::fake('public');

    $path = UploadedFile::fake()->image('existing.jpg')->store('logos', 'public');
    $this->business->update(['logo' => $path]);
    Storage::disk('public')->assertExists($path);

    $this->actingAs($this->admin)
        ->put('/dashboard/settings/profile', [
            'name' => 'Test Salon',
            'slug' => 'test-salon',
            'logo' => '',
        ])
        ->assertRedirect('/dashboard/settings/profile');

    expect($this->business->fresh()->logo)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('staff cannot access profile settings', function () {
    $staff = User::factory()->create();
    attachStaff($this->business, $staff);

    $this->actingAs($staff)
        ->get('/dashboard/settings/profile')
        ->assertForbidden();
});
