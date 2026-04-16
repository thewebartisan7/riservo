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
        $business = $request->user()->currentBusiness();

        return Inertia::render('dashboard/settings/booking', [
            'settings' => [
                'confirmation_mode' => $business->confirmation_mode->value,
                'allow_provider_choice' => $business->allow_provider_choice,
                'cancellation_window_hours' => $business->cancellation_window_hours,
                'payment_mode' => $business->payment_mode->value,
                'assignment_strategy' => $business->assignment_strategy->value,
                'reminder_hours' => $business->reminder_hours ?? [],
            ],
        ]);
    }

    public function update(UpdateBookingSettingsRequest $request): RedirectResponse
    {
        $business = $request->user()->currentBusiness();
        $validated = $request->validated();
        $validated['reminder_hours'] = array_map('intval', $validated['reminder_hours'] ?? []);
        $business->update($validated);

        return redirect()->route('settings.booking')->with('success', __('Booking settings updated.'));
    }
}
