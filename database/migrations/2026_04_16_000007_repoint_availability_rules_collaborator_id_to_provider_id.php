<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('availability_rules', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_id')->nullable()->after('id');
        });

        DB::transaction(function () {
            $rules = DB::table('availability_rules')->select('id', 'business_id', 'collaborator_id')->get();

            foreach ($rules as $rule) {
                $providerId = DB::table('providers')
                    ->where('business_id', $rule->business_id)
                    ->where('user_id', $rule->collaborator_id)
                    ->value('id');

                if ($providerId === null) {
                    throw new RuntimeException(
                        "Could not resolve provider for availability_rule {$rule->id}."
                    );
                }

                DB::table('availability_rules')->where('id', $rule->id)->update(['provider_id' => $providerId]);
            }
        });

        Schema::table('availability_rules', function (Blueprint $table) {
            $table->dropIndex(['collaborator_id', 'day_of_week']);
            $table->dropForeign(['collaborator_id']);
            $table->dropColumn('collaborator_id');
        });

        Schema::table('availability_rules', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_id')->nullable(false)->change();
            $table->foreign('provider_id')->references('id')->on('providers')->restrictOnDelete();
            $table->index(['provider_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::table('availability_rules', function (Blueprint $table) {
            $table->foreignId('collaborator_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
        });

        DB::transaction(function () {
            $rules = DB::table('availability_rules')->select('id', 'provider_id')->get();

            foreach ($rules as $rule) {
                $userId = DB::table('providers')->where('id', $rule->provider_id)->value('user_id');

                DB::table('availability_rules')->where('id', $rule->id)->update(['collaborator_id' => $userId]);
            }
        });

        Schema::table('availability_rules', function (Blueprint $table) {
            $table->dropIndex(['provider_id', 'day_of_week']);
            $table->dropForeign(['provider_id']);
            $table->dropColumn('provider_id');
        });

        Schema::table('availability_rules', function (Blueprint $table) {
            $table->unsignedBigInteger('collaborator_id')->nullable(false)->change();
            $table->index(['collaborator_id', 'day_of_week']);
        });
    }
};
