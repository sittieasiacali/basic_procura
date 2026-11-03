<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('checked_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable()->after('checked_by');
            $table->text('checker_remarks')->nullable()->after('checked_at');
            $table->foreignId('acknowledged_by')->nullable()->after('checked_by')->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable()->after('acknowledged_by');
            $table->text('acknowledgement_remarks')->nullable()->after('acknowledged_at');
            $table->foreignId('approved_by')->nullable()->after('acknowledged_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('approval_remarks')->nullable()->after('approved_at');
            $table->text('rejection_reason')->nullable()->after('approval_remarks');
        });

        Schema::table('budget_requests', function (Blueprint $table) {
            $table->string('main_fund')->nullable()->after('fund_source');
        });
    }

    public function down(): void
    {
        Schema::table('budget_requests', function (Blueprint $table) {
            $table->dropColumn('main_fund');
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checked_by');
            $table->dropColumn(['checked_at', 'checker_remarks']);
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn(['acknowledged_at', 'acknowledgement_remarks']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approved_at', 'approval_remarks', 'rejection_reason']);
        });
    }
};
