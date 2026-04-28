<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PAYMENTS Hardening Round 1 — F-004 (idempotent recordSettlementFailure).
 *
 * `RefundService::recordSettlementFailure` upserts a `payment.refund_failed`
 * Pending Action via `where(...)->first()` + `create()`. Different Stripe
 * events for the same failed refund (`charge.refund.updated`, `refund.updated`,
 * second delivery of the same id) carry different event ids, so the
 * cache-layer dedup (`stripe:connect:event:{id}`) does not collapse them.
 * Under concurrent delivery both requests can observe `existing === null`
 * and each insert a row, leaving duplicate refund-failed records.
 *
 * Mirrors the dispute partial unique index (D-126,
 * `2026_04_23_054441_add_dispute_id_unique_index_to_pending_actions.php`).
 * The handler's insert path now relies on the constraint to reject the
 * race-loser via `UniqueConstraintViolationException` inside a savepoint.
 *
 * Postgres-only by design (riservo is Postgres-only per D-065).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pre-flight: the index would fail to add if duplicate rows already
        // exist. Pre-launch the table is empty / `migrate:fresh` is the norm,
        // so this guard is belt-and-braces for future deploys.
        $duplicates = DB::selectOne(
            "SELECT count(*) AS n FROM (
                SELECT payload->>'booking_refund_id' AS k
                FROM pending_actions
                WHERE type = 'payment.refund_failed'
                GROUP BY k
                HAVING count(*) > 1
            ) AS d"
        );

        if ($duplicates !== null && (int) $duplicates->n > 0) {
            throw new RuntimeException(
                'Cannot add unique index: pending_actions has duplicate '
                .'payment.refund_failed rows. Resolve manually before deploying.'
            );
        }

        DB::statement(
            "CREATE UNIQUE INDEX pending_actions_refund_failed_unique
             ON pending_actions (((payload->>'booking_refund_id')))
             WHERE type = 'payment.refund_failed'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pending_actions_refund_failed_unique');
    }
};
