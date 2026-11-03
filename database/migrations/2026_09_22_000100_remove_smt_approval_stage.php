<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('purchase_requests')
            ->where('status', 'FOR_OM_SMTS_APPROVAL')
            ->update([
                'status' => 'FOR_TEAM_SCREENING',
                'request_type' => 'UNCLASSIFIED',
                'assigned_user_id' => null,
                'current_responsible_role_id' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // The original reviewer and request classification cannot be recovered safely.
    }
};
