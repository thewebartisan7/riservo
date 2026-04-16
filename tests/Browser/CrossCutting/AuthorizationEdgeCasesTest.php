<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Tests\Browser\Support\BusinessSetup;

// Covers: cross-tenant scoping (D-063), token tampering, signed-URL tampering (E2E-6).

it('404s when an admin of Business A visits a customer detail page for Business B', function () {
    ['admin' => $adminA] = BusinessSetup::createLaunchedBusiness();
    ['business' => $businessB, 'provider' => $providerB, 'service' => $serviceB]
        = BusinessSetup::createLaunchedBusiness();

    $customerB = Customer::factory()->create();
    Booking::factory()->confirmed()->create([
        'business_id' => $businessB->id,
        'provider_id' => $providerB->id,
        'service_id' => $serviceB->id,
        'customer_id' => $customerB->id,
    ]);

    $this->actingAs($adminA);

    $this->get('/dashboard/customers/'.$customerB->id)->assertNotFound();
});

it('returns a not-found page when a booking token is tampered with', function () {
    $page = visit('/bookings/this-is-not-a-valid-token-000000000000');

    $page->assertSee('not found');
});

it('rejects an invalid signature on a magic-link verify URL', function () {
    $user = User::factory()->create();
    $user->forceFill(['magic_link_token' => 'correct-token'])->save();

    $validUrl = URL::temporarySignedRoute(
        'magic-link.verify',
        now()->addMinutes(15),
        ['user' => $user->id, 'token' => 'correct-token'],
    );

    // Tamper the signature query param.
    $tampered = preg_replace('/signature=[^&]+/', 'signature=tamperedbadsig', $validUrl);

    $this->get($tampered)->assertStatus(403);
});
