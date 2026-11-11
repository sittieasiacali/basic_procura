<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('team', 20)->default('PURCHASING')->index();
        });

        // Preserve IDs and historical document links. Mixed/unknown history
        // remains in Purchasing; exclusively IT/Premises suppliers move to that team.
        DB::table('suppliers')->orderBy('id')->chunkById(200, function ($suppliers): void {
            foreach ($suppliers as $supplier) {
                $roles = DB::table('supplier_quotations')
                    ->join('users', 'users.id', '=', 'supplier_quotations.created_by')
                    ->join('roles', 'roles.id', '=', 'users.role_id')
                    ->where('supplier_quotations.supplier_id', $supplier->id)
                    ->distinct()->pluck('roles.slug');
                if ($roles->count() === 1 && in_array($roles->first(), ['it-staff', 'premises-staff'], true)) {
                    DB::table('suppliers')->where('id', $supplier->id)->update([
                        'team' => $roles->first() === 'it-staff' ? 'IT' : 'PREMISES',
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropIndex(['team']));
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('team'));
    }
};
