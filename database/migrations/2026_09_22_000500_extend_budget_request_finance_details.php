<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('account_number')->nullable()->after('name');
        });

        Schema::table('budget_requests', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('purchase_order_id')->constrained()->nullOnDelete();
            $table->string('campaign_account_number')->nullable()->after('campaign_id');
            $table->date('payment_date')->nullable()->after('requested_at');
            $table->string('fund_source')->nullable()->after('payment_method');
            $table->text('source_bank_account')->nullable()->after('fund_source');
            $table->foreignId('budget_custodian_id')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
        });

        DB::table('campaigns')->where('name', 'Operations')->whereNull('account_number')->update([
            'account_number' => 'OPERATIONS-001',
        ]);

        $presidentRoleId = DB::table('roles')->where('slug', 'president')->value('id');
        DB::table('purchase_requests')
            ->whereIn('status', ['FOR_BUDGET_CHECKER', 'FOR_BUDGET_PROCEEDER'])
            ->update([
                'status' => 'AWAITING_PRESIDENT_FINAL_APPROVAL',
                'current_responsible_role_id' => $presidentRoleId,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('budget_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropConstrainedForeignId('budget_custodian_id');
            $table->dropColumn(['campaign_account_number', 'payment_date', 'fund_source', 'source_bank_account']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('account_number');
        });
    }
};
