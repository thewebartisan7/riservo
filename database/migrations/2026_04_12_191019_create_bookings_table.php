<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collaborator_id')->constrained('users');
            $table->foreignId('service_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status')->default(BookingStatus::Pending->value);
            $table->string('source')->default(BookingSource::Riservo->value);
            $table->string('external_calendar_id')->nullable();
            $table->string('payment_status')->default(PaymentStatus::Pending->value);
            $table->text('notes')->nullable();
            $table->string('cancellation_token')->unique();
            $table->timestamps();

            $table->index(['business_id', 'starts_at']);
            $table->index(['collaborator_id', 'starts_at']);
            $table->index('customer_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
