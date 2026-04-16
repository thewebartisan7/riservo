<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_id')->nullable()->after('business_id');
        });

        DB::transaction(function () {
            $bookings = DB::table('bookings')->select('id', 'business_id', 'collaborator_id')->get();

            foreach ($bookings as $booking) {
                $providerId = DB::table('providers')
                    ->where('business_id', $booking->business_id)
                    ->where('user_id', $booking->collaborator_id)
                    ->value('id');

                if ($providerId === null) {
                    throw new RuntimeException(
                        "Could not resolve provider for booking {$booking->id} (business={$booking->business_id}, user={$booking->collaborator_id})."
                    );
                }

                DB::table('bookings')->where('id', $booking->id)->update(['provider_id' => $providerId]);
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['collaborator_id', 'starts_at']);
            $table->dropForeign(['collaborator_id']);
            $table->dropColumn('collaborator_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_id')->nullable(false)->change();
            $table->foreign('provider_id')->references('id')->on('providers')->restrictOnDelete();
            $table->index(['provider_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('collaborator_id')->nullable()->after('business_id')->constrained('users');
        });

        DB::transaction(function () {
            $bookings = DB::table('bookings')->select('id', 'provider_id')->get();

            foreach ($bookings as $booking) {
                $userId = DB::table('providers')->where('id', $booking->provider_id)->value('user_id');

                DB::table('bookings')->where('id', $booking->id)->update(['collaborator_id' => $userId]);
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['provider_id', 'starts_at']);
            $table->dropForeign(['provider_id']);
            $table->dropColumn('provider_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('collaborator_id')->nullable(false)->change();
            $table->index(['collaborator_id', 'starts_at']);
        });
    }
};
