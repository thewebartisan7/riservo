<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAYMENTS Session 3 — Codex Round 1 P2 fix. Persist the admin's free-form
 * "reason" note from the manual-refund dialog. D-174 locks `reason` as a
 * five-value vocabulary string (cancelled-after-payment, customer-requested,
 * business-cancelled, admin-manual, business-rejected-pending); overloading
 * it with free-text would break that invariant. Store the note separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_refunds', function (Blueprint $table) {
            $table->text('admin_note')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('booking_refunds', function (Blueprint $table) {
            $table->dropColumn('admin_note');
        });
    }
};
