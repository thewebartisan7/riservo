<?php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;

test('booking page loads for onboarded business', function () {
    $this->withoutVite();
    $business = Business::factory()->onboarded()->create();
    Service::factory()->create(['business_id' => $business->id, 'is_active' => true]);

    $response = $this->get('/'.$business->slug);

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('booking/show')
        ->has('business')
        ->has('services', 1)
        ->where('business.slug', $business->slug)
    );
});

test('returns 404 for non-existent slug', function () {
    $response = $this->get('/non-existent-business');

    $response->assertStatus(404);
});

test('returns 404 for non-onboarded business', function () {
    $business = Business::factory()->create();

    $response = $this->get('/'.$business->slug);

    $response->assertStatus(404);
});

test('only active services are shown', function () {
    $this->withoutVite();
    $business = Business::factory()->onboarded()->create();
    Service::factory()->create(['business_id' => $business->id, 'is_active' => true, 'name' => 'Active']);
    Service::factory()->create(['business_id' => $business->id, 'is_active' => false, 'name' => 'Inactive']);

    $response = $this->get('/'.$business->slug);

    $response->assertInertia(fn ($page) => $page
        ->has('services', 1)
    );
});

test('service pre-selection via URL sets preSelectedServiceSlug', function () {
    $this->withoutVite();
    $business = Business::factory()->onboarded()->create();
    $service = Service::factory()->create(['business_id' => $business->id, 'is_active' => true, 'slug' => 'haircut']);

    $response = $this->get('/'.$business->slug.'/haircut');

    $response->assertInertia(fn ($page) => $page
        ->where('preSelectedServiceSlug', 'haircut')
    );
});

test('invalid service slug is ignored', function () {
    $this->withoutVite();
    $business = Business::factory()->onboarded()->create();
    Service::factory()->create(['business_id' => $business->id, 'is_active' => true]);

    $response = $this->get('/'.$business->slug.'/nonexistent-service');

    $response->assertInertia(fn ($page) => $page
        ->where('preSelectedServiceSlug', null)
    );
});

test('logged-in customer gets prefill data', function () {
    $this->withoutVite();
    $business = Business::factory()->onboarded()->create();
    Service::factory()->create(['business_id' => $business->id, 'is_active' => true]);

    $user = User::factory()->create();
    $customer = Customer::factory()->create([
        'user_id' => $user->id,
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+41 79 123 45 67',
    ]);

    $response = $this->actingAs($user)->get('/'.$business->slug);

    $response->assertInertia(fn ($page) => $page
        ->where('customerPrefill.name', 'Jane Doe')
        ->where('customerPrefill.email', 'jane@example.com')
        ->where('customerPrefill.phone', '+41 79 123 45 67')
    );
});

test('guest user gets null prefill', function () {
    $this->withoutVite();
    $business = Business::factory()->onboarded()->create();
    Service::factory()->create(['business_id' => $business->id, 'is_active' => true]);

    $response = $this->get('/'.$business->slug);

    $response->assertInertia(fn ($page) => $page
        ->where('customerPrefill', null)
    );
});
