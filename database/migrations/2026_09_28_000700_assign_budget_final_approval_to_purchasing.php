<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $purchasingRoleId = DB::table('roles')->where('slug', 'purchasing')->value('id');

        if ($purchasingRoleId === null) {
            return;
        }

        DB::table('purchase_requests')
            ->whereIn('status', [
                'FOR_BUDGET_MAKER',
                'FOR_BUDGET_CHECKER',
                'FOR_BUDGET_PROCEEDER',
                'AWAITING_PRESIDENT_FINAL_APPROVAL',
            ])
            ->update(['current_responsible_role_id' => $purchasingRoleId]);
    }

    public function down(): void
    {
        // The prior role varied across legacy records, so it cannot be restored reliably.
    }
};
