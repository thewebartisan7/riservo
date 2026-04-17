<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Subscription;

beforeEach(function () {
    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($this->business, $this->admin);

    // Mark this business as fully read-only — subscription ended.
    Subscription::factory()
        ->for($this->business, 'owner')
        ->canceled()
        ->withPrice('price_test_monthly')
        ->create([
            'ends_at' => now()->subDay(),
        ]);

    $this->business->refresh();
});

// Routes without route model bindings — the middleware fires before controllers
// in all cases, but SubstituteBindings runs after billing.writable, so routes
// with `{model}` placeholders only fail with 404 (still proves the gate isn't
// bypassed, but adds noise). The dataset focuses on routes with no bindings to
// keep the assertion tight.
dataset('mutating routes', [
    'manual booking create' => ['POST', '/dashboard/bookings'],
    'profile update' => ['PUT', '/dashboard/settings/profile'],
    'logo upload' => ['POST', '/dashboard/settings/profile/logo'],
    'booking settings update' => ['PUT', '/dashboard/settings/booking'],
    'hours update' => ['PUT', '/dashboard/settings/hours'],
    'exception store' => ['POST', '/dashboard/settings/exceptions'],
    'service store' => ['POST', '/dashboard/settings/services'],
    'staff invite' => ['POST', '/dashboard/settings/staff/invite'],
    'account toggle provider' => ['POST', '/dashboard/settings/account/toggle-provider'],
    'calendar-integration connect' => ['POST', '/dashboard/settings/calendar-integration/connect'],
    'calendar-integration sync-now' => ['POST', '/dashboard/settings/calendar-integration/sync-now'],
    'calendar-integration disconnect' => ['DELETE', '/dashboard/settings/calendar-integration'],
]);

test('read-only business cannot mutate via the dashboard', function (string $verb, string $path) {
    expect($this->business->canWrite())->toBeFalse();

    $this->actingAs($this->admin)
        ->call($verb, $path)
        ->assertRedirect('/dashboard/settings/billing');
})->with('mutating routes');

test('read-only business can still read dashboard pages', function () {
    foreach (['/dashboard', '/dashboard/bookings', '/dashboard/calendar', '/dashboard/customers'] as $path) {
        $this->actingAs($this->admin)
            ->get($path)
            ->assertOk();
    }
});

test('read-only business can still reach billing routes', function () {
    $this->actingAs($this->admin)
        ->get('/dashboard/settings/billing')
        ->assertOk();
});

test('read-only business POST to subscribe is allowed (not gated)', function () {
    config([
        'billing.prices.monthly' => null,
    ]);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/subscribe', ['plan' => 'monthly'])
        ->assertRedirect('/dashboard/settings/billing')
        ->assertSessionHas('error');
});

test('webhook endpoint is reachable regardless of billing state', function () {
    config(['cashier.webhook.secret' => null]);

    $this->postJson('/webhooks/stripe', [
        'id' => 'evt_readonly_test',
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_readonly_test',
            'customer' => 'cus_no_match',
            'status' => 'active',
            'items' => ['data' => []],
        ]],
    ])->assertOk();
});

test('public booking page is reachable for a read-only business slug', function () {
    $this->get('/'.$this->business->slug)->assertOk();
});

test('an active business is not gated', function () {
    $active = Business::factory()->onboarded()->create();
    Subscription::factory()
        ->for($active, 'owner')
        ->active()
        ->withPrice('price_test_monthly')
        ->create();
    $admin = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($active, $admin);

    expect($active->refresh()->canWrite())->toBeTrue();

    // A POST that would normally do work — staff invite — should NOT redirect
    // to billing (validation will likely fail with 422, but not a redirect to
    // /dashboard/settings/billing).
    $response = $this->actingAs($admin)
        ->from('/dashboard/settings/staff')
        ->post('/dashboard/settings/staff/invite', ['email' => 'invitee@example.com', 'role' => 'staff']);

    $response->assertSessionMissing('error');
});

/**
 * Structural invariant: every mutating dashboard route must be wrapped by the
 * billing.writable middleware. Walks the route table directly so it covers
 * routes with model bindings (which the HTTP dataset above can't, because
 * SubstituteBindings runs after billing.writable and a missing model 404s
 * before the gate's redirect can fire). Carve-outs are explicit one-liners —
 * a future contributor adding a new exception has to think about it.
 */
test('every mutating dashboard route is gated by billing.writable', function () {
    $unprotected = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'dashboard/'))
        ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
        // Carve-out: billing routes must remain reachable while read-only so
        // a lapsed admin can resubscribe / open the portal / cancel / resume.
        ->reject(fn ($route) => str_starts_with($route->uri(), 'dashboard/settings/billing'))
        ->reject(fn ($route) => in_array('billing.writable', $route->gatherMiddleware(), true))
        ->values();

    expect($unprotected->all())->toBe(
        [],
        'Mutating dashboard routes outside the billing.writable gate: '
        .$unprotected->map(fn ($r) => implode('|', array_diff($r->methods(), ['HEAD'])).' '.$r->uri())->implode(', ')
    );
});
