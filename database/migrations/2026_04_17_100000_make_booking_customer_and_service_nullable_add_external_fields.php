<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MVPC-2: external Google Calendar events land as bookings with no customer and
 * no service. Relax the two FKs to nullable, add `external_title` for the event
 * summary, and add `external_html_link` for the Google event's htmlLink (D-084
 * revised — dedicated column; `internal_notes` stays admin-notes-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
            $table->foreignId('service_id')->nullable()->change();

            $table->string('external_title')->nullable()->after('external_calendar_id');
            $table->string('external_html_link')->nullable()->after('external_title');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('external_html_link');
            $table->dropColumn('external_title');

            $table->foreignId('service_id')->nullable(false)->change();
            $table->foreignId('customer_id')->nullable(false)->change();
        });
    }
};
