<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A request may go to Budget without a Purchase Order. The PO preparer records
 * that decision, and the Budget Request allocation then has no PO to point to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->timestamp('purchase_order_skipped_at')->nullable();
            $table->foreignId('purchase_order_skipped_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('budget_request_purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_order_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_skipped_by');
            $table->dropColumn('purchase_order_skipped_at');
        });
    }
};
