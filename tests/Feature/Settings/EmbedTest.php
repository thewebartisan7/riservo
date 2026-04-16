<?php

use App\Models\Business;
use App\Models\Service;
use App\Models\User;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create(['slug' => 'test-salon']);
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);
});

test('admin can view embed settings', function () {
    $this->actingAs($this->admin)
        ->get('/dashboard/settings/embed')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/settings/embed')
            ->where('slug', 'test-salon')
            ->has('baseUrl')
            ->has('embedUrl')
        );
});

test('embed page includes services for pre-filtering', function () {
    Service::factory()->count(2)->create([
        'business_id' => $this->business->id,
        'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->get('/dashboard/settings/embed')
        ->assertInertia(fn ($page) => $page->has('services', 2));
});

test('public booking page passes embed prop', function () {
    Service::factory()->create(['business_id' => $this->business->id, 'is_active' => true]);

    $this->get('/test-salon?embed=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('embed', true));
});

test('public booking page without embed param has embed false', function () {
    Service::factory()->create(['business_id' => $this->business->id, 'is_active' => true]);

    $this->get('/test-salon')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('embed', false));
});
