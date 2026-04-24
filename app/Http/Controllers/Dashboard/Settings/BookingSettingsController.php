<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Settings\UpdateBookingSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $business = tenant()->business();
        $business->loadMissing('stripeConnectedAccount');
        $row = $business->stripeConnectedAccount;
        $supported = (array) config('payments.supported_countries');

        return Inertia::render('dashboard/settings/booking', [
            'settings' => [
                'confirmation_mode' => $business->confirmation_mode->value,
                'allow_provider_choice' => $business->allow_provider_choice,
                'cancellation_window_hours' => $business->cancellation_window_hours,
                'payment_mode' => $business->payment_mode->value,
                'assignment_strategy' => $business->assignment_strategy->value,
                'reminder_hours' => $business->reminder_hours ?? [],
            ],
            // PAYMENTS Session 5: three-flag eligibility block so the React
            // page can render non-offline options disabled with the
            // priority-ordered tooltip (not connected → non-CH → reserved).
            //
            // `has_verified_account` reports ONLY the Stripe side: row exists,
            // capability booleans all on, no disabled_reason. It deliberately
            // does NOT fold in the country check — otherwise a DE-active
            // account would surface as "not verified" and the tooltip would
            // pick the wrong reason ("Connect Stripe" instead of "non-CH"),
            // flipping the priority order the roadmap specifies.
            //
            // `country_supported` is independent.
            //
            // `can_accept_online_payments` is the aggregate authoritative bit
            // (D-127 folds both in); the other two select the correct
            // disabled-reason copy.
            'paymentEligibility' => [
                'has_verified_account' => $row !== null
                    && $row->charges_enabled
                    && $row->payouts_enabled
                    && $row->details_submitted
                    && $row->requirements_disabled_reason === null,
                'country_supported' => $row !== null && in_array($row->country, $supported, true),
                'can_accept_online_payments' => $business->canAcceptOnlinePayments(),
                'connected_account_country' => $row?->country,
                'supported_countries' => $supported,
            ],
        ]);
    }

    public function update(UpdateBookingSettingsRequest $request): RedirectResponse
    {
        $business = tenant()->business();
        $validated = $request->validated();
        $validated['reminder_hours'] = array_map('intval', $validated['reminder_hours'] ?? []);
        $business->update($validated);

        return redirect()->route('settings.booking')->with('success', __('Booking settings updated.'));
    }
}
