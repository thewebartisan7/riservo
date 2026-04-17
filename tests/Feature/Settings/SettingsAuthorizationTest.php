<?php

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($this->business, $this->admin);

    $this->staff = User::factory()->create(['email_verified_at' => now()]);
    attachStaff($this->business, $this->staff);
});

test('staff cannot access any admin-only settings page', function () {
    $routes = [
        '/dashboard/settings/profile',
        '/dashboard/settings/booking',
        '/dashboard/settings/hours',
        '/dashboard/settings/exceptions',
        '/dashboard/settings/services',
        '/dashboard/settings/staff',
        '/dashboard/settings/embed',
        '/dashboard/settings/billing',
    ];

    foreach ($routes as $route) {
        $this->actingAs($this->staff)
            ->get($route)
            ->assertForbidden();
    }
});

test('staff can access shared settings pages', function () {
    $this->actingAs($this->staff)
        ->get('/dashboard/settings/calendar-integration')
        ->assertOk();

    $this->actingAs($this->staff)
        ->get('/dashboard/settings/account')
        ->assertOk();
});

test('staff with an active provider row can access availability', function () {
    attachProvider($this->business, $this->staff);

    $this->actingAs($this->staff)
        ->get('/dashboard/settings/availability')
        ->assertOk();
});

test('staff without an active provider row gets 404 on availability', function () {
    $this->actingAs($this->staff)
        ->get('/dashboard/settings/availability')
        ->assertNotFound();
});

test('staff cannot toggle provider on themselves', function () {
    $this->actingAs($this->staff)
        ->post('/dashboard/settings/account/toggle-provider')
        ->assertForbidden();
});

test('staff with provider row cannot edit which services they perform', function () {
    attachProvider($this->business, $this->staff);

    $this->actingAs($this->staff)
        ->put('/dashboard/settings/availability/services', ['service_ids' => []])
        ->assertForbidden();
});

test('soft-deleted staff member cannot reach any settings page', function () {
    BusinessMember::where('business_id', $this->business->id)
        ->where('user_id', $this->staff->id)
        ->first()
        ->delete();

    $this->actingAs($this->staff)
        ->get('/dashboard/settings/account')
        ->assertForbidden();

    $this->actingAs($this->staff)
        ->get('/dashboard/settings/calendar-integration')
        ->assertForbidden();
});

test('unauthenticated users are redirected to login', function () {
    $this->get('/dashboard/settings/profile')
        ->assertRedirect('/login');
});

test('admin can access all settings pages', function () {
    attachProvider($this->business, $this->admin);

    $routes = [
        '/dashboard/settings/profile',
        '/dashboard/settings/booking',
        '/dashboard/settings/hours',
        '/dashboard/settings/exceptions',
        '/dashboard/settings/services',
        '/dashboard/settings/staff',
        '/dashboard/settings/embed',
        '/dashboard/settings/account',
        '/dashboard/settings/availability',
        '/dashboard/settings/billing',
        '/dashboard/settings/calendar-integration',
    ];

    foreach ($routes as $route) {
        $this->actingAs($this->admin)
            ->get($route)
            ->assertOk();
    }
});
