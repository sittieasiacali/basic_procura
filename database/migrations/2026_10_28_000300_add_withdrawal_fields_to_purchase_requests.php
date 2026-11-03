<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            // Requestor withdrawal: who asked, why, when, and the stage where processing stopped.
            $table->text('withdrawal_reason')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('withdrawal_stage', 60)->nullable();
            $table->foreignId('withdrawal_role_id')->nullable()->constrained('roles')->nullOnDelete();
            // Staff acknowledgment that voids the request.
            $table->foreignId('withdrawal_acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawal_acknowledged_at')->nullable();
            $table->text('withdrawal_acknowledgment_remarks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('withdrawn_by');
            $table->dropConstrainedForeignId('withdrawal_role_id');
            $table->dropConstrainedForeignId('withdrawal_acknowledged_by');
            $table->dropColumn(['withdrawal_reason', 'withdrawn_at', 'withdrawal_stage', 'withdrawal_acknowledged_at', 'withdrawal_acknowledgment_remarks']);
        });
    }
};
