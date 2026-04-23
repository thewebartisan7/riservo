<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PAYMENTS Session 1 codex Round-3 fix (D-126):
 *
 * The dispute webhook handler upserted a `payment.dispute_opened` Pending
 * Action via `first()`-then-`create()`. Different Stripe events for the
 * same dispute (`charge.dispute.created` and `charge.dispute.updated`)
 * carry different event ids, so the cache-layer dedup
 * (`stripe:connect:event:{id}`) does not collapse them. Under concurrent
 * delivery — Stripe can dispatch both events nearly simultaneously and the
 * web tier accepts both — both requests can observe `existing === null`
 * and each insert a row, leaving duplicate dispute records.
 *
 * Fix: a Postgres partial unique index on `payload->>'dispute_id'`
 * scoped to `type = 'payment.dispute_opened'`. The handler's insert path
 * now relies on the constraint to reject the race-loser, with a try/catch
 * that converts the rejection into the "update existing" branch.
 *
 * Schema Builder has no fluent API for partial / expression indexes; the
 * migration uses raw SQL. Postgres-only by design (riservo is Postgres-
 * only per D-065).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE UNIQUE INDEX pending_actions_dispute_id_unique
             ON pending_actions (((payload->>'dispute_id')))
             WHERE type = 'payment.dispute_opened'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pending_actions_dispute_id_unique');
    }
};
