<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $rows = DB::table('business_members')
                ->where('role', 'staff')
                ->get(['business_id', 'user_id', 'is_active', 'created_at', 'updated_at']);

            foreach ($rows as $row) {
                DB::table('providers')->insert([
                    'business_id' => $row->business_id,
                    'user_id' => $row->user_id,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                    'deleted_at' => $row->is_active ? null : $row->updated_at,
                ]);
            }

            $expected = DB::table('business_members')->where('role', 'staff')->count();
            $actual = DB::table('providers')->count();

            if ($expected !== $actual) {
                throw new RuntimeException(
                    "Provider backfill mismatch: expected {$expected}, got {$actual}."
                );
            }
        });
    }

    public function down(): void
    {
        DB::table('providers')->truncate();
    }
};
