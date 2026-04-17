<?php

use App\Models\Business;
use App\Models\User;
use Laravel\Cashier\Subscription;
use Tests\Support\Billing\FakeStripeClient;

beforeEach(function () {
    config([
        'billing.prices.monthly' => 'price_test_monthly',
        'billing.prices.annual' => 'price_test_annual',
        'cashier.secret' => 'sk_test_dummy',
    ]);

    $this->business = Business::factory()->onboarded()->create();
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    attachAdmin($this->business, $this->admin);

    $this->staff = User::factory()->create(['email_verified_at' => now()]);
    attachStaff($this->business, $this->staff);
});

test('admin sees the billing page in trial state', function () {
    $this->actingAs($this->admin)
        ->get('/dashboard/settings/billing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/settings/billing')
            ->where('subscription.status', 'trial')
            ->where('subscription.trial_ends_at', null)
            ->where('plans.monthly.amount', 29)
            ->where('plans.annual.amount', 290)
            ->where('has_stripe_keys', true)
        );
});

test('staff cannot see the billing page', function () {
    $this->actingAs($this->staff)
        ->get('/dashboard/settings/billing')
        ->assertForbidden();
});

test('subscribe with monthly plan opens stripe checkout and redirects', function () {
    FakeStripeClient::for($this)
        ->mockCustomerCreate('cus_test_new')
        ->mockCustomerUpdate()
        ->mockCheckoutSession(['url' => 'https://checkout.stripe.com/c/pay/cs_test_monthly']);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/subscribe', ['plan' => 'monthly'])
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_monthly');
});

test('subscribe rejects unknown plan with 422', function () {
    $this->actingAs($this->admin)
        ->from('/dashboard/settings/billing')
        ->post('/dashboard/settings/billing/subscribe', ['plan' => 'lifetime'])
        ->assertSessionHasErrors('plan');
});

test('subscribe flashes an error when prices are unconfigured', function () {
    config(['billing.prices.monthly' => null]);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/subscribe', ['plan' => 'monthly'])
        ->assertRedirect('/dashboard/settings/billing')
        ->assertSessionHas('error');
});

test('subscribe rejects blank string price ids — env() returns "" not null', function () {
    // .env.example ships STRIPE_PRICE_MONTHLY= which env() resolves to '';
    // the controller must treat that as unconfigured and short-circuit.
    config(['billing.prices.monthly' => '']);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/subscribe', ['plan' => 'monthly'])
        ->assertRedirect('/dashboard/settings/billing')
        ->assertSessionHas('error');
});

test('subscribe is blocked when the business already has an active subscription', function () {
    Subscription::factory()
        ->for($this->business, 'owner')
        ->active()
        ->withPrice('price_test_monthly')
        ->create();

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/subscribe', ['plan' => 'monthly'])
        ->assertRedirect('/dashboard/settings/billing')
        ->assertSessionHas('error');
});

test('subscribe is blocked when the business has a canceling subscription in grace', function () {
    Subscription::factory()
        ->for($this->business, 'owner')
        ->active()
        ->withPrice('price_test_monthly')
        ->create([
            'ends_at' => now()->addDays(5),
        ]);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/subscribe', ['plan' => 'monthly'])
        ->assertRedirect('/dashboard/settings/billing')
        ->assertSessionHas('error');
});

test('subscribe is allowed for a read_only business so they can resubscribe', function () {
    Subscription::factory()
        ->for($this->business, 'owner')
        ->canceled()
        ->withPrice('price_test_monthly')
        ->create([
            'ends_at' => now()->subDay(),
        ]);

    FakeStripeClient::for($this)
        ->mockCustomerCreate('cus_test_resubscribe')
        ->mockCustomerUpdate()
        ->mockCheckoutSession(['url' => 'https://checkout.stripe.com/c/pay/cs_test_resubscribe']);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/subscribe', ['plan' => 'monthly'])
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_resubscribe');
});

test('billing page reports has_stripe_keys=false when env values are blank strings', function () {
    config([
        'cashier.secret' => '',
        'billing.prices.monthly' => '',
        'billing.prices.annual' => '',
    ]);

    $this->actingAs($this->admin)
        ->get('/dashboard/settings/billing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('has_stripe_keys', false)
        );
});

test('subscribe short-circuits with a friendly flash when STRIPE_SECRET is blank', function () {
    config(['cashier.secret' => '']);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/subscribe', ['plan' => 'monthly'])
        ->assertRedirect('/dashboard/settings/billing')
        ->assertSessionHas('error');
});

test('portal short-circuits with a friendly flash when STRIPE_SECRET is blank', function () {
    config(['cashier.secret' => '']);
    $this->business->update(['stripe_id' => 'cus_seeded']);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/portal')
        ->assertRedirect('/dashboard/settings/billing')
        ->assertSessionHas('error');
});

test('cancel short-circuits with a friendly flash when STRIPE_SECRET is blank', function () {
    Subscription::factory()
        ->for($this->business, 'owner')
        ->active()
        ->withPrice('price_test_monthly')
        ->create();

    config(['cashier.secret' => '']);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/cancel')
        ->assertRedirect('/dashboard/settings/billing')
        ->assertSessionHas('error');
});

test('cashier.payment route is registered after Cashier::ignoreRoutes()', function () {
    // Cashier::ignoreRoutes() suppresses both the default webhook AND the
    // payment confirmation page. The latter is the redirect target for
    // IncompletePayment exceptions (SCA / 3DS off-session renewals). If the
    // name doesn't resolve, every Cashier IncompletePayment->payment->id
    // redirect explodes with a RouteNotFoundException.
    expect(route('cashier.payment', ['id' => 'pi_test_123']))
        ->toEndWith('/stripe/payment/pi_test_123');
});

test('portal still works when price ids are blank but the Stripe secret is set', function () {
    // Partial-config recovery path: an admin rotates prices and temporarily
    // has STRIPE_PRICE_* blank. Existing subscribers must still be able to
    // open the portal / cancel / resume — the guard is deliberately narrower
    // than hasStripeKeys() for this exact reason.
    config([
        'cashier.secret' => 'sk_test_dummy',
        'billing.prices.monthly' => '',
        'billing.prices.annual' => '',
    ]);
    $this->business->update(['stripe_id' => 'cus_rotating']);

    FakeStripeClient::for($this)
        ->mockCustomerUpdate()
        ->mockBillingPortalSession(['url' => 'https://billing.stripe.com/p/session/rotating']);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/portal')
        ->assertRedirect('https://billing.stripe.com/p/session/rotating');
});

test('cancel still works when price ids are blank but the Stripe secret is set', function () {
    Subscription::factory()
        ->for($this->business, 'owner')
        ->active()
        ->withPrice('price_test_monthly')
        ->create();

    config([
        'cashier.secret' => 'sk_test_dummy',
        'billing.prices.monthly' => '',
        'billing.prices.annual' => '',
    ]);

    FakeStripeClient::for($this)
        ->mockCustomerUpdate()
        ->mockSubscriptionUpdate(['cancel_at_period_end' => true]);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/cancel')
        ->assertRedirect('/dashboard/settings/billing')
        ->assertSessionHas('success');
});

test('resume short-circuits with a friendly flash when STRIPE_SECRET is blank', function () {
    Subscription::factory()
        ->for($this->business, 'owner')
        ->active()
        ->withPrice('price_test_monthly')
        ->create([
            'ends_at' => now()->addDays(5),
        ]);

    config(['cashier.secret' => '']);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/resume')
        ->assertRedirect('/dashboard/settings/billing')
        ->assertSessionHas('error');
});

test('portal flashes error when business has no stripe customer yet', function () {
    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/portal')
        ->assertRedirect('/dashboard/settings/billing')
        ->assertSessionHas('error');
});

test('portal redirects to stripe billing portal when configured', function () {
    $this->business->update(['stripe_id' => 'cus_test_existing']);

    FakeStripeClient::for($this)
        ->mockCustomerUpdate()
        ->mockBillingPortalSession(['url' => 'https://billing.stripe.com/p/session/test']);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/portal')
        ->assertRedirect('https://billing.stripe.com/p/session/test');
});

test('cancel marks the active subscription with cancel_at_period_end and flashes success', function () {
    Subscription::factory()
        ->for($this->business, 'owner')
        ->active()
        ->withPrice('price_test_monthly')
        ->create(['stripe_id' => 'sub_test_cancel']);

    FakeStripeClient::for($this)
        ->mockCustomerUpdate()
        ->mockSubscriptionUpdate(['cancel_at_period_end' => true]);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/cancel')
        ->assertRedirect('/dashboard/settings/billing')
        ->assertSessionHas('success');
});

test('cancel flashes error when there is no active subscription', function () {
    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/cancel')
        ->assertRedirect('/dashboard/settings/billing')
        ->assertSessionHas('error');
});

test('resume flashes error when subscription is not in grace', function () {
    Subscription::factory()
        ->for($this->business, 'owner')
        ->active()
        ->withPrice('price_test_monthly')
        ->create();

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/resume')
        ->assertRedirect('/dashboard/settings/billing')
        ->assertSessionHas('error');
});

test('resume calls stripe and flashes success when subscription is in grace', function () {
    Subscription::factory()
        ->for($this->business, 'owner')
        ->active()
        ->withPrice('price_test_monthly')
        ->create([
            'stripe_id' => 'sub_test_resume',
            'ends_at' => now()->addDays(5),
        ]);

    FakeStripeClient::for($this)
        ->mockCustomerUpdate()
        ->mockSubscriptionUpdate(['cancel_at_period_end' => false]);

    $this->actingAs($this->admin)
        ->post('/dashboard/settings/billing/resume')
        ->assertRedirect('/dashboard/settings/billing')
        ->assertSessionHas('success');
});
