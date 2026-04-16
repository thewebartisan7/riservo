<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
            // provider_id null = business-level exception (D-021)
            $table->date('start_date');
            $table->date('end_date'); // single-day: start_date == end_date (D-018)
            $table->time('start_time')->nullable(); // null = full day
            $table->time('end_time')->nullable();   // null = full day
            $table->string('type'); // block | open
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'start_date', 'end_date']);
            $table->index(['provider_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_exceptions');
    }
};
