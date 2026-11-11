<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', fn (Blueprint $table) => $table->text('bank_details')->nullable());
        // Reuse each supplier's latest recorded instructions without changing historical quotations.
        DB::table('suppliers')->orderBy('id')->chunkById(200, function ($suppliers): void {
            foreach ($suppliers as $supplier) {
                $details = DB::table('supplier_quotations')->where('supplier_id', $supplier->id)
                    ->whereNull('deleted_at')->whereNotNull('bank_details')->where('bank_details', '<>', '')
                    ->latest('id')->value('bank_details');
                if ($details !== null) {
                    DB::table('suppliers')->where('id', $supplier->id)->update(['bank_details' => $details]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('bank_details'));
    }
};
