<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('purchase_requests')
            ->whereIn('status', ['FOR_OM_APPROVAL', 'FOR_OM_SMTS_APPROVAL'])
            ->orderBy('id')->chunkById(100, function ($requests) {
                foreach ($requests as $request) {
                    $team = in_array($request->requested_team, ['IT', 'PREMISES'], true) ? $request->requested_team : null;
                    $slug = match ($team) { 'IT' => 'it-staff', 'PREMISES' => 'premises-staff', default => null };
                    DB::table('purchase_requests')->where('id', $request->id)->update([
                        'status' => match ($team) { 'IT' => 'FOR_IT_SCREENING', 'PREMISES' => 'FOR_PREMISES_SCREENING', default => 'FOR_TEAM_SCREENING' },
                        'request_type' => $team ?? 'UNCLASSIFIED',
                        'assigned_user_id' => null,
                        'current_responsible_role_id' => $slug === null ? null : DB::table('roles')->where('slug', $slug)->value('id'),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Management approval is retired; requests must not return to that queue.
    }
};
