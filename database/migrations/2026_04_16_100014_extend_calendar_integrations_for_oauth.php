<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_integrations', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'provider']);

            $table->string('google_account_email')->nullable()->after('calendar_id');
            $table->timestamp('token_expires_at')->nullable()->after('refresh_token');

            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::table('calendar_integrations', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'provider']);

            $table->dropColumn('token_expires_at');
            $table->dropColumn('google_account_email');

            $table->index(['user_id', 'provider']);
        });
    }
};
