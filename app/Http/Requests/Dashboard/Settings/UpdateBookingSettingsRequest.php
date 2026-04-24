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
     * PAYMENTS Session 5 gate (locked roadmap decisions #27 / #43): non-offline
     * `payment_mode` values are accepted iff the business is genuinely
     * eligible for online payments. `canAcceptOnlinePayments()` (D-127) is
     * the single reader that folds in:
     *   - an active connected-account row exists,
     *   - Stripe capability booleans (charges_enabled / payouts_enabled /
     *     details_submitted) are all on,
     *   - `requirements_disabled_reason` is null (D-138),
     *   - the account's country is in `config('payments.supported_countries')`
     *     (D-141 / D-150).
     *
     * This replaces the D-132 transitional hard-block that covered Sessions
     * 1–4 (UI hide in force, no booking flow consuming `payment_mode`). The
     * Session-5 React page mirrors this gate to disable options client-side
     * with a tooltip, but the server-side rule is the enforcement edge
     * (client-side disable is convenience; a direct PUT still 422s).
     *
     * Idempotent passthrough is kept verbatim: a PUT that re-sends the
     * currently-persisted value is always accepted. This keeps the form
     * usable when the persisted value is non-offline but the admin is
     * editing unrelated fields, and handles the case of a legitimately-set
     * business whose eligibility later lapsed (connected account was
     * disconnected, country config tightened) — they can still edit other
     * fields without getting a 422 on the round-tripped hidden input. The
     * demotion-to-offline paths (Connect webhook + disconnect controller)
     * are the mechanisms that ACTUALLY reset the persisted value, not
     * a form-submit edge.
     */
    private function paymentModeRolloutRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === PaymentMode::Offline->value) {
                return;
            }

            $business = tenant()->business();

            if ($business !== null && $business->payment_mode->value === $value) {
                // Idempotent passthrough — the value isn't changing.
                return;
            }

            if ($business !== null && $business->canAcceptOnlinePayments()) {
                return;
            }

            // Round-3 codex review P3: pick the right reason copy. A
            // verified-but-non-CH account is already connected and KYC'd,
            // so "Connect Stripe" misleads the admin. The priority order
            // mirrors the React tooltip (not connected → non-CH → other)
            // so the server-side 422 message matches the UI they saw.
            //
            // NOTE on CH-centric copy (locked roadmap decision #43): the
            // non-CH message below is DELIBERATELY CH-specific for MVP.
            // D-43 draws an explicit distinction — the `supported_countries`
            // config gate stays the single source of truth for the STATE
            // (gate, branching, tests), while "this roadmap's copy, UX,
            // and tax assumptions are all CH-centric". The fast-follow
            // roadmap that extends to IT / DE / FR / AT / LI is defined
            // as "config change + TWINT fallback verification +
            // locale-list audit" — meaning the copy is updated WHEN the
            // config is extended, not before. Rendering the supported
            // list dynamically here is YAGNI for MVP (config is `['CH']`)
            // and contradicts D-43's locale-audit contract. Do not flag
            // as a review issue — this is intentional.
            $row = $business?->stripeConnectedAccount;
            $isVerifiedStripe = $row !== null
                && $row->charges_enabled
                && $row->payouts_enabled
                && $row->details_submitted
                && $row->requirements_disabled_reason === null;
            $supported = (array) config('payments.supported_countries');

            if ($isVerifiedStripe && ! in_array($row->country, $supported, true)) {
                $fail(__('Online payments in MVP support CH-located businesses only.'));

                return;
            }

            $fail(__('Connect Stripe and complete verification before enabling online payments.'));
        };
    }
}
