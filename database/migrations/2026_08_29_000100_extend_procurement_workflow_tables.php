<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->foreignId('selected_supplier_id')->nullable()->after('current_responsible_role_id')->constrained('suppliers')->nullOnDelete();
        });

        Schema::table('supplier_quotations', function (Blueprint $table) {
            $table->string('delivery_lead_time')->nullable()->after('delivery_terms');
            $table->string('warranty')->nullable()->after('delivery_lead_time');
            $table->string('quotation_url')->nullable()->after('warranty');
            $table->boolean('is_approved')->default(false)->after('is_recommended')->index();
            $table->foreignId('approved_by')->nullable()->after('is_approved')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        Schema::table('budget_requests', function (Blueprint $table) {
            $table->foreignId('maker_user_id')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            $table->foreignId('checker_user_id')->nullable()->after('maker_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('proceeder_user_id')->nullable()->after('checker_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('final_approval_by')->nullable()->after('proceeder_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('maker_approved_at')->nullable()->after('final_approval_by');
            $table->timestamp('checker_approved_at')->nullable()->after('maker_approved_at');
            $table->timestamp('proceeder_approved_at')->nullable()->after('checker_approved_at');
            $table->timestamp('final_approval_at')->nullable()->after('proceeder_approved_at');
            $table->string('final_approval_status')->nullable()->after('final_approval_at');
            $table->text('rejection_reason')->nullable()->after('final_approval_status');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_channel')->nullable()->after('payment_method');
            $table->string('payment_status')->nullable()->after('status');
            $table->timestamp('payment_date')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['payment_channel', 'payment_status', 'payment_date']);
        });

        Schema::table('budget_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('maker_user_id');
            $table->dropConstrainedForeignId('checker_user_id');
            $table->dropConstrainedForeignId('proceeder_user_id');
            $table->dropConstrainedForeignId('final_approval_by');
            $table->dropColumn([
                'maker_approved_at',
                'checker_approved_at',
                'proceeder_approved_at',
                'final_approval_at',
                'final_approval_status',
                'rejection_reason',
            ]);
        });

        Schema::table('supplier_quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['delivery_lead_time', 'warranty', 'quotation_url', 'is_approved', 'approved_at']);
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('selected_supplier_id');
        });
    }
};
