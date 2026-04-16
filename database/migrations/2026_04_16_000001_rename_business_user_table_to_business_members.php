<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('business_user', 'business_members');

        DB::table('business_members')->where('role', 'collaborator')->update(['role' => 'staff']);
        DB::table('business_invitations')->where('role', 'collaborator')->update(['role' => 'staff']);
    }

    public function down(): void
    {
        DB::table('business_invitations')->where('role', 'staff')->update(['role' => 'collaborator']);
        DB::table('business_members')->where('role', 'staff')->update(['role' => 'collaborator']);

        Schema::rename('business_members', 'business_user');
    }
};
