<?php

use App\Models\Business;
use App\Models\User;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create();
    $this->business->users()->attach($this->admin, ['role' => 'admin']);

    $this->collaborator = User::factory()->create();
    $this->business->users()->attach($this->collaborator, ['role' => 'collaborator']);
});

test('collaborator cannot access any settings page', function () {
    $routes = [
        '/dashboard/settings/profile',
        '/dashboard/settings/booking',
        '/dashboard/settings/hours',
        '/dashboard/settings/exceptions',
        '/dashboard/settings/services',
        '/dashboard/settings/collaborators',
        '/dashboard/settings/embed',
    ];

    foreach ($routes as $route) {
        $this->actingAs($this->collaborator)
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
        '/dashboard/settings/collaborators',
        '/dashboard/settings/embed',
    ];

    foreach ($routes as $route) {
        $this->actingAs($this->admin)
            ->get($route)
            ->assertOk();
    }
});
