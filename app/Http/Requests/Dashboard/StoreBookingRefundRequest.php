<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PAYMENTS Session 3 — validation for the admin-manual refund dialog
 * (`Dashboard\BookingRefundController::store`).
 *
 * The controller enforces admin-only + tenant-scoping via `tenant()`;
 * `authorize()` here returns true so the FormRequest focuses on shape.
 *
 * Shape:
 *   - `kind`: "full" or "partial"
 *   - `amount_cents`: required when `kind=partial`, integer ≥ 1
 *   - `reason`: optional free-form admin note (≤ 500 chars)
 *
 * The server-side overflow check (`amount_cents` exceeds
 * `remainingRefundableCents`) is enforced inside `RefundService::refund` per
 * locked decision #37 + D-169, which throws a `ValidationException`
 * rendered inline by the dialog — doing it here as well would race against
 * concurrent refunds by another admin. The service's lock is authoritative.
 */
class StoreBookingRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['full', 'partial'])],
            'amount_cents' => ['required_if:kind,partial', 'nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
