<?php

namespace App\Models;

use Database\Factories\StripeConnectedAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Per-Business Stripe Connect Express account.
 *
 * Created when an admin clicks "Enable online payments" on the Connected
 * Account settings page (PAYMENTS Session 1). Updated by the
 * /webhooks/stripe-connect handler on every account.updated nudge — see
 * locked roadmap decision #34: every Stripe account event is treated as a
 * "something changed" signal and the handler re-fetches the authoritative
 * state via `stripe.accounts.retrieve($accountId)` rather than trusting the
 * payload's ordering.
 *
 * Soft-deleted on disconnect; `stripe_account_id` is retained on the
 * trashed row so Session 2b's late-webhook refund path (locked decision
 * #36) can still target the original account id.
 *
 * @property int $id
 * @property int $business_id
 * @property string $stripe_account_id
 * @property string $country ISO-3166-1 alpha-2; Stripe authoritative.
 * @property bool $charges_enabled
 * @property bool $payouts_enabled
 * @property bool $details_submitted
 * @property array<int, string>|null $requirements_currently_due
 * @property string|null $requirements_disabled_reason
 * @property string|null $default_currency ISO-4217 lower-cased per Stripe convention.
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'business_id',
    'stripe_account_id',
    'country',
    'charges_enabled',
    'payouts_enabled',
    'details_submitted',
    'requirements_currently_due',
    'requirements_disabled_reason',
    'default_currency',
])]
class StripeConnectedAccount extends Model
{
    /** @use HasFactory<StripeConnectedAccountFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'charges_enabled' => 'boolean',
            'payouts_enabled' => 'boolean',
            'details_submitted' => 'boolean',
            'requirements_currently_due' => 'array',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Verification state buckets surfaced to the dashboard. Plain strings
     * (not an enum) — Stripe's own verification states are richer than a
     * local enum can meaningfully carry; collapsing them here gives the
     * frontend just enough to branch on.
     *
     * - 'disabled'            — Stripe disabled the account (`requirements_disabled_reason` is non-null).
     * - 'unsupported_market'  — Stripe capabilities are fully on, but the row's `country` is not in
     *                           `config('payments.supported_countries')`. Codex Round-13 (D-150):
     *                           without this state, a config/env flip removing the row's country
     *                           from the supported set would show "Verified / Active" in the UI
     *                           while `Business::canAcceptOnlinePayments()` still blocks the backend,
     *                           stranding the admin with no explanation.
     * - 'active'              — fully verified AND in a supported market; can charge and accept payouts.
     * - 'incomplete'          — KYC submitted but Stripe still missing one or more capabilities.
     * - 'pending'             — KYC not yet submitted (account created, user bailed mid-flow).
     */
    public function verificationStatus(): string
    {
        if ($this->requirements_disabled_reason !== null) {
            return 'disabled';
        }

        if ($this->charges_enabled && $this->payouts_enabled && $this->details_submitted) {
            // D-150: Stripe caps are on but riservo's supported-countries
            // config no longer includes this row's country. Distinct
            // state so the UI can explain the mismatch instead of
            // showing a misleading "Verified" chip that contradicts
            // `Business::canAcceptOnlinePayments()` (D-127, D-141).
            if (! in_array($this->country, (array) config('payments.supported_countries'), true)) {
                return 'unsupported_market';
            }

            return 'active';
        }

        if ($this->details_submitted) {
            return 'incomplete';
        }

        return 'pending';
    }

    /**
     * Outcome-level idempotency check (locked roadmap decision #33). Used by
     * the Connect webhook handler to skip a no-op DB write when the row
     * already matches what Stripe authoritatively reports.
     *
     * Order-insensitive comparison on `requirements_currently_due` so a
     * Stripe-side reordering of the array is not treated as a state change.
     *
     * @param  array{
     *     country?: string,
     *     charges_enabled?: bool,
     *     payouts_enabled?: bool,
     *     details_submitted?: bool,
     *     requirements_currently_due?: array<int, string>,
     *     requirements_disabled_reason?: string|null,
     *     default_currency?: string|null
     * }  $fields
     */
    public function matchesAuthoritativeState(array $fields): bool
    {
        foreach ($fields as $key => $value) {
            $current = $this->getAttribute($key);

            if ($key === 'requirements_currently_due') {
                $a = $current ?? [];
                $b = $value;
                sort($a);
                sort($b);
                if ($a !== $b) {
                    return false;
                }

                continue;
            }

            if ($current !== $value) {
                return false;
            }
        }

        return true;
    }
}
