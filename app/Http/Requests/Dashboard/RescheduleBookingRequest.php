<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate shape only — authorization and business-rule checks live in the
 * controller (see D-096 precedent: route middleware is the role gate, the
 * controller re-derives resources from the actor). The tenant/staff/external
 * guards in DashboardBookingController::reschedule cannot be expressed in a
 * FormRequest without re-plumbing state that the controller already has.
 *
 * Request shape (D-105): `starts_at` (UTC ISO-8601), `duration_minutes`.
 * Server recomputes ends_at so client and server cannot drift.
 */
class RescheduleBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return tenant()->has();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            // Upper bound is 24h; the controller enforces the snap-to-interval
            // and the not-straddling-two-days rule using the booking's service.
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
