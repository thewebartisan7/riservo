<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')
                ->constrained('calendar_integrations')
                ->cascadeOnDelete();
            $table->string('calendar_id');
            $table->string('channel_id')->unique();
            $table->string('resource_id');
            $table->string('channel_token');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['integration_id', 'calendar_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_watches');
    }
};
