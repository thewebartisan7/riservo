<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('availability_exceptions', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_id')->nullable()->after('business_id');
        });

        DB::transaction(function () {
            $exceptions = DB::table('availability_exceptions')
                ->select('id', 'business_id', 'collaborator_id')
                ->whereNotNull('collaborator_id')
                ->get();

            foreach ($exceptions as $exception) {
                $providerId = DB::table('providers')
                    ->where('business_id', $exception->business_id)
                    ->where('user_id', $exception->collaborator_id)
                    ->value('id');

                if ($providerId === null) {
                    throw new RuntimeException(
                        "Could not resolve provider for availability_exception {$exception->id}."
                    );
                }

                DB::table('availability_exceptions')
                    ->where('id', $exception->id)
                    ->update(['provider_id' => $providerId]);
            }
        });

        Schema::table('availability_exceptions', function (Blueprint $table) {
            $table->dropIndex(['collaborator_id', 'start_date', 'end_date']);
            $table->dropForeign(['collaborator_id']);
            $table->dropColumn('collaborator_id');
        });

        Schema::table('availability_exceptions', function (Blueprint $table) {
            $table->foreign('provider_id')->references('id')->on('providers')->nullOnDelete();
            $table->index(['provider_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::table('availability_exceptions', function (Blueprint $table) {
            $table->foreignId('collaborator_id')->nullable()->after('business_id')->constrained('users')->nullOnDelete();
        });

        DB::transaction(function () {
            $exceptions = DB::table('availability_exceptions')
                ->select('id', 'provider_id')
                ->whereNotNull('provider_id')
                ->get();

            foreach ($exceptions as $exception) {
                $userId = DB::table('providers')->where('id', $exception->provider_id)->value('user_id');

                DB::table('availability_exceptions')
                    ->where('id', $exception->id)
                    ->update(['collaborator_id' => $userId]);
            }
        });

        Schema::table('availability_exceptions', function (Blueprint $table) {
            $table->dropIndex(['provider_id', 'start_date', 'end_date']);
            $table->dropForeign(['provider_id']);
            $table->dropColumn('provider_id');
            $table->index(['collaborator_id', 'start_date', 'end_date']);
        });
    }
};
