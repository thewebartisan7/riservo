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
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedInteger('duration_minutes');
            $table->decimal('price', 8, 2)->nullable(); // null = "on request" (D-020)
            $table->unsignedInteger('buffer_before')->default(0);
            $table->unsignedInteger('buffer_after')->default(0);
            $table->unsignedInteger('slot_interval_minutes')->default(15); // D-015
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
