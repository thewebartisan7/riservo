<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAYMENTS Session 1 codex Round-1 fix (D-122):
 *
 * The original migration added `unique(['business_id', 'deleted_at'])`,
 * intending "one active row per business; soft-deleted rows can stack
 * alongside". That works on engines where NULLs are treated as equal in
 * unique constraints — but Postgres treats every NULL as distinct, so two
 * rows with `deleted_at IS NULL` for the same `business_id` were NOT
 * rejected. The advertised invariant was unenforced.
 *
 * Fix: drop the broken compound unique and create a partial unique index
 * that explicitly covers active rows only. Postgres supports this directly;
 * Schema Builder does not have a fluent API for partial indexes, so we
 * issue the raw DDL.
 *
 * The pre-existing `unique('stripe_account_id')` constraint is unaffected —
 * Stripe account ids are globally unique by Stripe's contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stripe_connected_accounts', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'deleted_at']);
        });

        DB::statement('CREATE UNIQUE INDEX stripe_connected_accounts_business_id_active_unique ON stripe_connected_accounts (business_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stripe_connected_accounts_business_id_active_unique');

        Schema::table('stripe_connected_accounts', function (Blueprint $table) {
            $table->unique(['business_id', 'deleted_at']);
        });
    }
};
