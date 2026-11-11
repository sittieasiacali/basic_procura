<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', fn (Blueprint $table) => $table->date('required_date')->nullable()->change());
        Schema::table('supplier_quotations', fn (Blueprint $table) => $table->decimal('shipping_fee', 12, 2)->default(0));
        Schema::table('purchase_orders', fn (Blueprint $table) => $table->string('delivery_lead_time')->nullable());
    }

    public function down(): void
    {
        // Optional dates cannot safely become mandatory while null records exist.
        Schema::table('supplier_quotations', fn (Blueprint $table) => $table->dropColumn('shipping_fee'));
        Schema::table('purchase_orders', fn (Blueprint $table) => $table->dropColumn('delivery_lead_time'));
    }
};
