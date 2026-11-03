<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->updateOrInsert(
            ['slug' => 'po-workflow'],
            [
                'name' => 'PO Workflow',
                'description' => 'Dedicated Purchase Order workflow account with no Purchase Request role access.',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('roles')->where('slug', 'po-workflow')->delete();
    }
};