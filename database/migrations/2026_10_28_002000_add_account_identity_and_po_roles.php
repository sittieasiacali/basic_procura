<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id', 100)->nullable()->unique();
            $table->string('username', 100)->nullable()->unique();
            $table->string('team_id', 100)->nullable();
            $table->boolean('must_change_password')->default(false);
        });

        foreach (['po-checker' => 'PO CHECKER', 'po-noted' => 'PO NOTED', 'po-approver' => 'PO APPROVER'] as $slug => $name) {
            DB::table('roles')->insertOrIgnore([
                'slug' => $slug, 'name' => $name,
                'description' => 'Dedicated '.strtolower($name).' workflow account.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['employee_id']);
            $table->dropUnique(['username']);
            $table->dropColumn(['employee_id', 'username', 'team_id', 'must_change_password']);
        });
        // Retain role records: existing accounts or PO history may reference them.
    }
};
