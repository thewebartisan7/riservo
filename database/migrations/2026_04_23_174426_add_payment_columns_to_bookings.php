<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAYMENTS Session 2a — locked roadmap decision #28.
 *
 * Extends `bookings` with the columns the online-payment flow needs:
 *   - stripe_checkout_session_id / stripe_payment_intent_id / stripe_charge_id
 *     → three nullable unique strings. `charge_id` is reserved for Session 3's
 *       refund path (2a writes only session_id + payment_intent_id).
 *   - paid_amount_cents / currency / paid_at → captured at booking creation
 *     from the connected account's default currency and the Service price;
 *     Stripe is authoritative on the settled value (handler logs + overwrites
 *     on mismatch) — see the PLAN.md Decision Log.
 *   - payment_mode_at_creation → the locked-decision-#14 snapshot. Always
 *     populated (default 'offline' for legacy rows + every writer). Mirrors
 *     Business.payment_mode at booking creation time; the manual /
 *     google_calendar carve-out per locked decision #30 always writes
 *     'offline' regardless of Business mode.
 *   - expires_at → mirrors the Checkout session's expires_at; only populated
 *     for payment_mode_at_creation=online per locked decision #13. Session
 *     2b's reaper filters on this column.
 *
 * Rolling-deploy-safe by construction (additive columns + index). No data
 * migration: riservo is pre-launch and dev DBs are `migrate:fresh --seed`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('stripe_charge_id')->nullable()->unique();
            $table->unsignedInteger('paid_amount_cents')->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_mode_at_creation')->default('offline');
            $table->timestamp('expires_at')->nullable();

            // Codex Round 2 (D-158): the connected account that minted the
            // Checkout session is pinned onto the booking at creation time,
            // so the webhook + success-page can cross-check against the
            // MINTING account regardless of disconnect+reconnect history.
            // Without this column, a business with a trashed historical
            // row + a fresh active row would make the
            // `withTrashed()->where('business_id')->value('stripe_account_id')`
            // lookup non-deterministic — legitimate late webhooks for
            // EITHER account could be rejected as cross-account mismatches.
            $table->string('stripe_connected_account_id')->nullable();

            // Session 2b's reaper filters on
            //   status=pending + payment_status=awaiting_payment
            //   + payment_mode_at_creation='online' + expires_at < now - 5min
            // scoped per business. Index on (business_id, expires_at) gives
            // the reaper a narrow range scan.
            $table->index(['business_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'expires_at']);
            $table->dropUnique(['stripe_checkout_session_id']);
            $table->dropUnique(['stripe_payment_intent_id']);
            $table->dropUnique(['stripe_charge_id']);
            $table->dropColumn([
                'stripe_checkout_session_id',
                'stripe_payment_intent_id',
                'stripe_charge_id',
                'paid_amount_cents',
                'currency',
                'paid_at',
                'payment_mode_at_creation',
                'expires_at',
                // F-007 (PAYMENTS Hardening Round 1): the original migration
                // omitted this column from down(), so a rollback+re-up cycle
                // would crash on "column already exists". D-133 explicitly
                // pins down() correctness as a non-negotiable invariant.
                'stripe_connected_account_id',
            ]);
        });
    }
};
