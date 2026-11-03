<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('budget_campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->string('budget_campaign_account_number')->nullable();
            $table->string('budget_account_type')->nullable();
            $table->string('budget_transaction_type')->nullable();
            $table->date('budget_payable_on')->nullable();
            $table->string('budget_fund_source')->nullable();
            $table->text('budget_source_bank_account')->nullable();
            $table->string('main_fund')->nullable();
            $table->string('requested_by')->nullable();
            $table->string('account_number')->nullable();
            $table->text('payment_details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('budget_campaign_id');
            $table->dropColumn([
                'budget_campaign_account_number', 'budget_account_type', 'budget_transaction_type',
                'budget_payable_on', 'budget_fund_source', 'budget_source_bank_account',
                'main_fund', 'requested_by', 'account_number', 'payment_details',
            ]);
        });
    }
};
