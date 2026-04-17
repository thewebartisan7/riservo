<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_pending_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')
                ->constrained('calendar_integrations')
                ->cascadeOnDelete();
            $table->foreignId('booking_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('type');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->foreignId('resolved_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['integration_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_pending_actions');
    }
};
