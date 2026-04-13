<?php

use App\Models\Business;
use App\Services\SlugService;

test('generates slug from name', function () {
    $service = new SlugService;

    expect($service->generateUniqueSlug('Salone Bella'))->toBe('salone-bella');
});

test('generates unique slug when taken', function () {
    Business::factory()->create(['slug' => 'salone-bella']);

    $service = new SlugService;

    expect($service->generateUniqueSlug('Salone Bella'))->toBe('salone-bella-2');
});

test('avoids reserved slugs', function () {
    $service = new SlugService;

    expect($service->generateUniqueSlug('Dashboard'))->toBe('dashboard-2')
        ->and($service->generateUniqueSlug('Login'))->toBe('login-2')
        ->and($service->generateUniqueSlug('API'))->toBe('api-2');
});

test('handles empty slug gracefully', function () {
    $service = new SlugService;

    expect($service->generateUniqueSlug('---'))->toBe('business');
});

test('identifies reserved slugs', function () {
    $service = new SlugService;

    expect($service->isReserved('dashboard'))->toBeTrue()
        ->and($service->isReserved('login'))->toBeTrue()
        ->and($service->isReserved('salone-bella'))->toBeFalse();
});
