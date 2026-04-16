<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['provider_id', 'service_id']);
        });

        DB::transaction(function () {
            $originalCount = DB::table('collaborator_service')->count();

            $rows = DB::table('collaborator_service as cs')
                ->join('services as s', 's.id', '=', 'cs.service_id')
                ->join('providers as p', function ($join) {
                    $join->on('p.business_id', '=', 's.business_id')
                        ->on('p.user_id', '=', 'cs.collaborator_id');
                })
                ->select(
                    'p.id as provider_id',
                    'cs.service_id as service_id',
                    'cs.created_at as created_at',
                    'cs.updated_at as updated_at',
                )
                ->get();

            foreach ($rows as $row) {
                DB::table('provider_service')->insert([
                    'provider_id' => $row->provider_id,
                    'service_id' => $row->service_id,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }

            $actual = DB::table('provider_service')->count();

            if ($originalCount !== $actual) {
                throw new RuntimeException(
                    "provider_service backfill mismatch: expected {$originalCount}, got {$actual}."
                );
            }
        });

        Schema::dropIfExists('collaborator_service');
    }

    public function down(): void
    {
        Schema::create('collaborator_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collaborator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['collaborator_id', 'service_id']);
        });

        DB::transaction(function () {
            $rows = DB::table('provider_service as ps')
                ->join('providers as p', 'p.id', '=', 'ps.provider_id')
                ->select(
                    'p.user_id as collaborator_id',
                    'ps.service_id as service_id',
                    'ps.created_at as created_at',
                    'ps.updated_at as updated_at',
                )
                ->get();

            foreach ($rows as $row) {
                DB::table('collaborator_service')->insert([
                    'collaborator_id' => $row->collaborator_id,
                    'service_id' => $row->service_id,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }
        });

        Schema::dropIfExists('provider_service');
    }
};
