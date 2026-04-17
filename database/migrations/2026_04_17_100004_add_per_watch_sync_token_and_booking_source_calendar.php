<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MVPC-2 review round 2:
 *
 *   (P1) Sync tokens are per-calendar in Google, not per-integration. Move
 *        `sync_token` onto `calendar_watches` so each watched calendar carries
 *        its own cursor. The existing integration-level column stays but is
 *        no longer written.
 *
 *   (P2) A booking's Google event lives in a specific calendar — the one that
 *        was the destination at push time. Reconfiguration changes the
 *        destination for FUTURE bookings; existing bookings must still delete
 *        / update their event in the original calendar. Add
 *        `bookings.external_event_calendar_id` to persist that origin.
 *
 * Existing rows:
 *   - `calendar_watches.sync_token` is null-backfilled; the next pull on each
 *     watch does a forward-only sync and stores the new token per-watch.
 *   - `bookings.external_event_calendar_id` is null-backfilled; the push job
 *     treats a null origin as "never pushed" for delete, which matches how
 *     `external_calendar_id = null` is already treated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_watches', function (Blueprint $table) {
            $table->text('sync_token')->nullable()->after('channel_token');
        });

        Schema::table('bookings', function (Blueprint $table) {
            // Google calendar id (NOT the event id — `external_calendar_id`
            // confusingly stores the event id from the pre-MVPC-1 migration).
            $table->string('external_event_calendar_id')
                ->nullable()
                ->after('external_calendar_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('external_event_calendar_id');
        });

        Schema::table('calendar_watches', function (Blueprint $table) {
            $table->dropColumn('sync_token');
        });
    }
};
