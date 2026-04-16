<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0)->after('ends_at');
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0)->after('buffer_before_minutes');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE bookings
              ADD COLUMN effective_starts_at TIMESTAMP(0)
                GENERATED ALWAYS AS (
                  starts_at - make_interval(mins => buffer_before_minutes::int)
                ) STORED,
              ADD COLUMN effective_ends_at TIMESTAMP(0)
                GENERATED ALWAYS AS (
                  ends_at + make_interval(mins => buffer_after_minutes::int)
                ) STORED
        SQL);

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
            ALTER TABLE bookings
              ADD CONSTRAINT bookings_no_provider_overlap
              EXCLUDE USING GIST (
                provider_id WITH =,
                tsrange(effective_starts_at, effective_ends_at, '[)') WITH &&
              ) WHERE (status IN ('pending', 'confirmed'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_no_provider_overlap');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['effective_starts_at', 'effective_ends_at']);
            $table->dropColumn(['buffer_before_minutes', 'buffer_after_minutes']);
        });
    }
};
