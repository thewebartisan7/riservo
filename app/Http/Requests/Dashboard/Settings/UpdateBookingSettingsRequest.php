<?php

namespace App\Http\Requests\Dashboard\Settings;

use App\Enums\AssignmentStrategy;
use App\Enums\BusinessMemberRole;
use App\Enums\ConfirmationMode;
use App\Enums\PaymentMode;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return tenant()->role() === BusinessMemberRole::Admin;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirmation_mode' => ['required', Rule::enum(ConfirmationMode::class)],
            'allow_provider_choice' => ['required', 'boolean'],
            'cancellation_window_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'payment_mode' => [
                'required',
                Rule::enum(PaymentMode::class),
                $this->paymentModeRolloutRule(),
            ],
            'assignment_strategy' => ['required', Rule::enum(AssignmentStrategy::class)],
            'reminder_hours' => ['sometimes', 'array'],
            'reminder_hours.*' => ['integer', Rule::in([1, 24])],
        ];
    }

    /**
     * Codex Round-4 (D-130) + Round-5 (D-132): the booking.tsx UI hides the
     * `online` / `customer_choice` options for Sessions 1–4 (locked roadmap
     * decision #27), and the server-side validator hard-blocks them
     * to match.
     *
     * Round-4 originally allowed verified-Stripe businesses to set
     * non-offline via direct PUT as a "dogfooding" carve-out. Codex Round 5
     * flagged this as a false-ready surface: no booking flow consumes
     * `payment_mode` yet (Session 2a wires Checkout, Session 5 lifts the
     * UI hide). An admin who flipped to `online` would believe customer
     * bookings were prepaid while the public flow still booked them
     * without collecting money. The carve-out is removed.
     *
     * Idempotent passthrough is kept: a PUT that re-sends the
     * currently-persisted value is allowed even if it's non-offline. This
     * keeps the form usable when the persisted value is `online` /
     * `customer_choice` (DB-seeded for development of Session 2a) and the
     * admin edits other booking-settings fields. The form's hidden input
     * round-trips the persisted value; the validator must accept it.
     *
     * Session 5 will swap this for an end-to-end check that includes
     * `canAcceptOnlinePayments()` (the country + Stripe capability gate)
     * AND a "Session 2a has shipped" feature flag — at that point the
     * carve-out becomes safe.
     */
    private function paymentModeRolloutRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($value === PaymentMode::Offline->value) {
                return;
            }

            $business = tenant()->business();

            if ($business !== null && $business->payment_mode->value === $value) {
                // Idempotent passthrough — the value isn't changing.
                return;
            }

            $fail(__('Online payment modes are not yet available. They will activate in a later release once the booking flow can collect payments.'));
        };
    }
}
