<?php

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
        Schema::create('availability_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collaborator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 1=Mon .. 7=Sun (ISO 8601, D-024)
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['collaborator_id', 'day_of_week']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('availability_rules');
    }
};
