<?php

use App\Models\Business;
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
        '/dashboard/settings/account',
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
});

test('unauthenticated users are redirected to login', function () {
    $this->get('/dashboard/settings/profile')
        ->assertRedirect('/login');
});

test('admin can access all settings pages', function () {
    $routes = [
        '/dashboard/settings/profile',
        '/dashboard/settings/booking',
        '/dashboard/settings/hours',
        '/dashboard/settings/exceptions',
        '/dashboard/settings/services',
        '/dashboard/settings/staff',
        '/dashboard/settings/embed',
        '/dashboard/settings/account',
        '/dashboard/settings/calendar-integration',
    ];

    foreach ($routes as $route) {
        $this->actingAs($this->admin)
            ->get($route)
            ->assertOk();
    }
});
