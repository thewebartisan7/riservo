<?php

use App\Models\Business;
use App\Models\User;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create();
    attachAdmin($this->business, $this->admin);

    $this->staff = User::factory()->create();
    attachStaff($this->business, $this->staff);
});

test('staff cannot access any settings page', function () {
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
    ];

    foreach ($routes as $route) {
        $this->actingAs($this->admin)
            ->get($route)
            ->assertOk();
    }
});
