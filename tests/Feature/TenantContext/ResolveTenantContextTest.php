<?php

use App\Enums\BusinessMemberRole;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Support\TenantContext;

beforeEach(function () {
    $this->withoutVite();
});

test('unauthenticated request leaves tenant context empty', function () {
    $this->get('/');

    expect(app(TenantContext::class)->has())->toBeFalse();
});

test('authenticated single-business user populates tenant context', function () {
    $user = User::factory()->create();
    $business = Business::factory()->onboarded()->create();
    attachAdmin($business, $user);

    $this->actingAs($user)->get('/dashboard');

    expect(session('current_business_id'))->toBe($business->id);
});

test('session pin to a valid membership wins', function () {
    $user = User::factory()->create();
    $businessA = Business::factory()->onboarded()->create();
    $businessB = Business::factory()->onboarded()->create();
    attachAdmin($businessA, $user);
    attachAdmin($businessB, $user);

    // Pin to B and then visit the dashboard; props should reflect B.
    $this->actingAs($user)
        ->withSession(['current_business_id' => $businessB->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.business.id', $businessB->id));

    // Session remains pinned to B.
    expect(session('current_business_id'))->toBe($businessB->id);
});

test('stale session value falls back to oldest active membership and rewrites session', function () {
    $user = User::factory()->create();
    $business = Business::factory()->onboarded()->create();
    attachAdmin($business, $user);

    $this->actingAs($user)
        ->withSession(['current_business_id' => 99999])
        ->get('/dashboard')
        ->assertOk();

    expect(session('current_business_id'))->toBe($business->id);
});

test('oldest active membership wins when multiple memberships and no session pin', function () {
    $user = User::factory()->create();

    // Create memberships with different created_at timestamps.
    $businessA = Business::factory()->onboarded()->create();
    $businessB = Business::factory()->onboarded()->create();

    $businessA->members()->attach($user, [
        'role' => BusinessMemberRole::Admin->value,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);
    $businessB->members()->attach($user, [
        'role' => BusinessMemberRole::Admin->value,
    ]);

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.business.id', $businessA->id));
});

test('customer-only user leaves tenant context empty', function () {
    $user = User::factory()->create();
    Customer::factory()->create(['user_id' => $user->id, 'email' => $user->email]);

    $this->actingAs($user)->get('/my-bookings')->assertOk();

    expect(app(TenantContext::class)->has())->toBeFalse();
    expect(session('current_business_id'))->toBeNull();
});
