<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One Final budget can group POs funded from different campaign source accounts under
 * one BR code, so each line keeps the source account its Budget Maker chose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_request_lines', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('purchase_order_id')->constrained()->nullOnDelete();
            $table->string('source_bank_account')->nullable()->after('campaign_id');
        });

        DB::table('budget_requests')->orderBy('id')->each(function ($budget) {
            DB::table('budget_request_lines')->where('budget_request_id', $budget->id)->update([
                'campaign_id' => $budget->campaign_id,
                'source_bank_account' => $budget->source_bank_account ?: $budget->campaign_account_number,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('budget_request_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropColumn('source_bank_account');
        });
    }
};
