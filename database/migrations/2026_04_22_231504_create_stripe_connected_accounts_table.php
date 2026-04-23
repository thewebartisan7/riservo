<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAYMENTS Session 1 — locked roadmap decisions #5, #20, #22, #36 (D-111).
 *
 * Per-Business connected-account row. Soft-deleted on disconnect; the cached
 * stripe_account_id is retained on the soft-deleted row so Session 2b's
 * late-webhook refund path (locked decision #36) still has an id to issue
 * against. The (business_id, deleted_at) compound unique mirrors D-079's
 * pattern for business_members: only one active row per business at a time;
 * soft-deleted rows can coexist alongside a future fresh row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_connected_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('stripe_account_id');
            $table->string('country', 2);
            $table->boolean('charges_enabled')->default(false);
            $table->boolean('payouts_enabled')->default(false);
            $table->boolean('details_submitted')->default(false);
            $table->json('requirements_currently_due')->nullable();
            $table->string('requirements_disabled_reason')->nullable();
            $table->string('default_currency', 3)->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['business_id', 'deleted_at']);
            $table->unique('stripe_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_connected_accounts');
    }
};
