<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Codex Round-9 finding (D-141): `ConnectedAccountController::create()`
 * used to pass `config('payments.default_onboarding_country')` straight
 * into `stripe.accounts.create()` with no code-level guarantee that the
 * business was actually in that country. Stripe Express country is
 * permanent after creation, so a non-CH tenant onboarding under
 * MVP-default CH would be stuck with an immutable wrong-jurisdiction
 * account.
 *
 * Canonical fix: add `businesses.country` (ISO-3166-1 alpha-2) so the
 * onboarding flow can consult a per-business value. MVP pre-launch has
 * one Swiss market, so existing rows are backfilled to 'CH'. A future
 * business-onboarding step will collect the real country for each new
 * tenant (BACKLOG: "Collect country during business onboarding").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('country', 2)->nullable()->after('timezone');
        });

        // Backfill: all MVP-era riservo businesses are Swiss. Pre-launch
        // dev + demo data gets the canonical value so existing onboarding
        // paths work without manual DB edits. Post-launch businesses will
        // collect this during onboarding.
        DB::table('businesses')->whereNull('country')->update(['country' => 'CH']);
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }
};
