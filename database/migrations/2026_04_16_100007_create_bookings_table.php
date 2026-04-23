<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('providers')->restrictOnDelete();
            $table->foreignId('service_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            $table->string('status')->default(BookingStatus::Pending->value);
            $table->string('source')->default(BookingSource::Riservo->value);
            $table->string('external_calendar_id')->nullable();
            // PAYMENTS Session 2a (locked roadmap decision #28): the pre-2a
            // `Pending` case on PaymentStatus was retired without a rename.
            // The fresh default is `not_applicable` (offline-from-the-start
            // booking, no payment ever expected). Every code path that
            // creates a booking writes the column explicitly — the default
            // only protects legacy / test-fixture rows.
            $table->string('payment_status')->default(PaymentStatus::NotApplicable->value);
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('cancellation_token')->unique();
            $table->timestamps();

            $table->index(['business_id', 'starts_at']);
            $table->index(['provider_id', 'starts_at']);
            $table->index('customer_id');
            $table->index('status');
        });

        // Generated columns for effective booking window (including buffers).
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

        // Overlap guard: no two pending/confirmed bookings for the same provider
        // may overlap (including buffers).
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
        Schema::dropIfExists('bookings');
    }
};
