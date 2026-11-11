<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', fn (Blueprint $table) => $table->json('item_budget_allocations')->nullable());
        Schema::table('budget_request_lines', fn (Blueprint $table) => $table->json('item_allocations')->nullable());
    }

    public function down(): void
    {
        Schema::table('budget_request_lines', fn (Blueprint $table) => $table->dropColumn('item_allocations'));
        Schema::table('purchase_orders', fn (Blueprint $table) => $table->dropColumn('item_budget_allocations'));
    }
};
