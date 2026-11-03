<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $financeRoleId = DB::table('roles')->where('slug', 'finance-budget-staff')->value('id');
        $purchasingRoleId = DB::table('roles')->where('slug', 'purchasing')->value('id');

        if ($financeRoleId === null || $purchasingRoleId === null) {
            return;
        }

        DB::table('purchase_requests')
            ->where('status', 'FOR_BUDGET_PROCESSING')
            ->where('current_responsible_role_id', $financeRoleId)
            ->update([
                'current_responsible_role_id' => $purchasingRoleId,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Intentionally left unchanged: reversing this migration could overwrite
        // a request that Purchasing has subsequently progressed.
    }
};