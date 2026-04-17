<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_integrations', function (Blueprint $table) {
            $table->foreignId('business_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('destination_calendar_id')->nullable()->after('calendar_id');
            $table->json('conflict_calendar_ids')->nullable()->after('destination_calendar_id');

            $table->text('sync_token')->nullable()->after('conflict_calendar_ids');
            $table->string('webhook_resource_id')->nullable()->after('webhook_channel_id');
            $table->string('webhook_channel_token')->nullable()->after('webhook_resource_id');

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamp('sync_error_at')->nullable();
            $table->text('push_error')->nullable();
            $table->timestamp('push_error_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('calendar_integrations', function (Blueprint $table) {
            $table->dropColumn([
                'push_error_at',
                'push_error',
                'sync_error_at',
                'sync_error',
                'last_pushed_at',
                'last_synced_at',
                'webhook_channel_token',
                'webhook_resource_id',
                'sync_token',
                'conflict_calendar_ids',
                'destination_calendar_id',
            ]);

            $table->dropConstrainedForeignId('business_id');
        });
    }
};
