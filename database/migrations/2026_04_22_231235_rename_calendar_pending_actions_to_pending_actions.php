<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAYMENTS Session 1, locked roadmap decision #44 (D-113):
 * Generalise the calendar-only pending-actions table so payment Pending
 * Actions in Sessions 2b / 3 can write to the same table without a fresh
 * schema session. Two changes — rename the table and make integration_id
 * nullable. The existing FK on integration_id keeps its cascadeOnDelete
 * behaviour for calendar-typed rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('calendar_pending_actions', 'pending_actions');

        Schema::table('pending_actions', function (Blueprint $table) {
            $table->foreignId('integration_id')
                ->nullable()
                ->change();
        });
    }

    /**
     * Codex Round-5 finding (D-133): the prior `down()` restored
     * `integration_id NOT NULL` before renaming the table back. That
     * change is INCOMPATIBLE with payment-typed rows added in Session 1
     * (D-119 added `payment.dispute_opened` / `payment.refund_failed` /
     * `payment.cancelled_after_payment`; D-123 inserts dispute Pending
     * Actions with `integration_id = null`). After even one such row
     * exists, an emergency `migrate:rollback` would fail the NOT NULL
     * change and leave the deployment stuck mid-rollback.
     *
     * The fix is a partial rollback: we rename the table back to the
     * original name (so old code reading `calendar_pending_actions` works
     * again) but we LEAVE `integration_id` nullable. That is a strict
     * superset of the original NOT NULL constraint — old code that always
     * set `integration_id` continues to work; the schema is no stricter
     * than what the rolled-back code expects.
     *
     * Operators who want a clean restore-to-original-schema after a
     * rollback can run a follow-up DDL once any payment-typed rows have
     * been deleted manually:
     *   `ALTER TABLE calendar_pending_actions ALTER COLUMN integration_id SET NOT NULL;`
     */
    public function down(): void
    {
        Schema::rename('pending_actions', 'calendar_pending_actions');
    }
};
