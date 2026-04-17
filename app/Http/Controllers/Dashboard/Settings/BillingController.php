<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\SubscribeRequest;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * Billing surface for the active business (D-007, D-089..D-094).
 *
 * Admin-only; lives outside the EnsureBusinessCanWrite gate (D-090) so a
 * lapsed admin can always reach Subscribe / Portal / Cancel / Resume.
 */
class BillingController extends Controller
{
    public function show(): Response
    {
        $business = tenant()->business();

        $business->loadMissing('subscriptions');

        return Inertia::render('dashboard/settings/billing', [
            'subscription' => $business->subscriptionStateForPayload(),
            'plans' => $this->plans(),
            'has_stripe_keys' => $this->hasStripeKeys(),
        ]);
    }

    public function subscribe(SubscribeRequest $request): Responsable|RedirectResponse
    {
        // Subscribe needs the secret AND at least the requested plan's price id;
        // the plan-specific price check happens below.
        if ($guard = $this->guardStripeSecret()) {
            return $guard;
        }

        $business = tenant()->business();

        // Server-side guard: the billing page hides plan cards once a
        // subscription exists, but the POST route is still reachable. Block
        // duplicate Stripe subscriptions for the same business — admins should
        // resume / manage via the portal instead.
        if (! $business->onTrial() && $business->subscriptionState() !== 'read_only') {
            return redirect()
                ->route('settings.billing')
                ->with('error', __('You already have an active subscription. Use Manage billing to make changes.'));
        }

        $plan = $request->validated('plan');
        $priceId = config("billing.prices.{$plan}");

        // env() returns '' (empty string) for blank entries in .env.example;
        // treat blank/empty as unconfigured, not as a valid price id.
        if (blank($priceId)) {
            return redirect()
                ->route('settings.billing')
                ->with('error', __('Billing is not configured. Please contact support.'));
        }

        return $business->newSubscription('default', $priceId)
            ->checkout([
                'success_url' => route('settings.billing').'?checkout=success',
                'cancel_url' => route('settings.billing').'?checkout=cancel',
            ]);
    }

    public function portal(): SymfonyRedirectResponse|RedirectResponse
    {
        // Portal / cancel / resume only need a Stripe secret — they act on the
        // business's existing customer + subscription, not on any price id.
        // Don't lock existing subscribers out of billing management just
        // because a price id got blanked during a rotation.
        if ($guard = $this->guardStripeSecret()) {
            return $guard;
        }

        $business = tenant()->business();

        if ($business->stripe_id === null) {
            return redirect()
                ->route('settings.billing')
                ->with('error', __('Billing portal is unavailable until you start a subscription.'));
        }

        return $business->redirectToBillingPortal(route('settings.billing'));
    }

    public function cancel(): RedirectResponse
    {
        if ($guard = $this->guardStripeSecret()) {
            return $guard;
        }

        $business = tenant()->business();

        $sub = $business->subscription('default');

        if ($sub === null || $sub->ended() || $sub->canceled()) {
            return redirect()
                ->route('settings.billing')
                ->with('error', __('There is no active subscription to cancel.'));
        }

        $sub->cancel();

        return redirect()
            ->route('settings.billing')
            ->with('success', __('Your subscription will end on :date.', [
                'date' => $sub->refresh()->ends_at?->translatedFormat('F j, Y'),
            ]));
    }

    public function resume(): RedirectResponse
    {
        if ($guard = $this->guardStripeSecret()) {
            return $guard;
        }

        $business = tenant()->business();

        $sub = $business->subscription('default');

        if ($sub === null || ! $sub->onGracePeriod()) {
            return redirect()
                ->route('settings.billing')
                ->with('error', __('There is no canceling subscription to resume.'));
        }

        $sub->resume();

        return redirect()
            ->route('settings.billing')
            ->with('success', __('Your subscription has been resumed.'));
    }

    /**
     * @return array<string, array{price_id: string|null, amount: int, currency: string, interval: string}>
     */
    private function plans(): array
    {
        $display = config('billing.display');
        $prices = config('billing.prices');

        return [
            'monthly' => [
                'price_id' => $prices['monthly'] ?? null,
                'amount' => $display['monthly']['amount'],
                'currency' => $display['monthly']['currency'],
                'interval' => $display['monthly']['interval'],
            ],
            'annual' => [
                'price_id' => $prices['annual'] ?? null,
                'amount' => $display['annual']['amount'],
                'currency' => $display['annual']['currency'],
                'interval' => $display['annual']['interval'],
            ],
        ];
    }

    private function hasStripeKeys(): bool
    {
        // env() returns '' for blank lines; treat blank as unconfigured so a
        // fresh install with empty STRIPE_* vars doesn't report billing as ready.
        return filled(config('cashier.secret'))
            && filled(config('billing.prices.monthly'))
            && filled(config('billing.prices.annual'));
    }

    /**
     * Short-circuit every mutating action when the Stripe secret is missing.
     * Without this guard, portal/cancel/resume would call the Stripe SDK with
     * a blank API key and surface raw `\Stripe\Exception\AuthenticationException`
     * to the user instead of the same friendly redirect the page already shows.
     *
     * Deliberately narrower than hasStripeKeys(): portal/cancel/resume operate
     * on the business's existing Stripe customer and subscription, NOT on any
     * price id. Gating those actions on `billing.prices.{monthly,annual}`
     * would lock active subscribers out of managing their subscription the
     * moment an admin rotates a price without filling both id envs at once.
     */
    private function guardStripeSecret(): ?RedirectResponse
    {
        if (filled(config('cashier.secret'))) {
            return null;
        }

        return redirect()
            ->route('settings.billing')
            ->with('error', __('Billing is not configured. Please contact support.'));
    }
}
