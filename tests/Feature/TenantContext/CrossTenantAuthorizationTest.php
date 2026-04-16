<?php

use App\Models\Business;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();

    // A user who is admin in business A and staff in business B.
    $this->user = User::factory()->create();
    $this->businessA = Business::factory()->onboarded()->create();
    $this->businessB = Business::factory()->onboarded()->create();

    attachAdmin($this->businessA, $this->user);
    attachStaff($this->businessB, $this->user);
});

test('admin settings allowed when pinned to business where user is admin', function () {
    $this->actingAs($this->user)
        ->withSession(['current_business_id' => $this->businessA->id])
        ->get('/dashboard/settings/services')
        ->assertOk();
});

test('admin settings forbidden when pinned to business where user is staff', function () {
    $this->actingAs($this->user)
        ->withSession(['current_business_id' => $this->businessB->id])
        ->get('/dashboard/settings/services')
        ->assertForbidden();
});

test('bookings page allowed in both pins but scoped to the pinned business', function () {
    Service::factory()->create(['business_id' => $this->businessA->id]);
    Service::factory()->create(['business_id' => $this->businessB->id]);

    $this->actingAs($this->user)
        ->withSession(['current_business_id' => $this->businessA->id])
        ->get('/dashboard/bookings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('isAdmin', true));

    $this->actingAs($this->user)
        ->withSession(['current_business_id' => $this->businessB->id])
        ->get('/dashboard/bookings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('isAdmin', false));
});

test('inertia auth props reflect the pinned tenant', function () {
    $this->actingAs($this->user)
        ->withSession(['current_business_id' => $this->businessA->id])
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('auth.business.id', $this->businessA->id)
            ->where('auth.role', 'admin'));

    $this->actingAs($this->user)
        ->withSession(['current_business_id' => $this->businessB->id])
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('auth.business.id', $this->businessB->id)
            ->where('auth.role', 'staff'));
});

test('provider schedule update rejects cross-tenant provider', function () {
    // A provider that belongs to business B — reachable via the user's staff membership
    // there, but not reachable when the session is pinned to business A.
    $providerInB = attachProvider($this->businessB, User::factory()->create());

    $payload = [
        'rules' => collect(range(1, 7))->map(fn ($day) => [
            'day_of_week' => $day,
            'enabled' => false,
            'windows' => [],
        ])->all(),
    ];

    $this->actingAs($this->user)
        ->withSession(['current_business_id' => $this->businessA->id])
        ->put("/dashboard/settings/providers/{$providerInB->id}/schedule", $payload)
        ->assertForbidden();
});
