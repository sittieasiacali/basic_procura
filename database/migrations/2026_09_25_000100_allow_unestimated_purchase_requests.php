<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->decimal('total_amount', 15, 2)->nullable()->default(null)->change();
        });

        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 2)->nullable()->change();
            $table->string('unit', 50)->nullable()->change();
            $table->decimal('estimated_unit_price', 15, 2)->nullable()->default(null)->change();
            $table->decimal('estimated_total', 15, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->decimal('total_amount', 15, 2)->default(0)->change();
        });

        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 2)->nullable(false)->change();
            $table->string('unit', 50)->nullable(false)->change();
            $table->decimal('estimated_unit_price', 15, 2)->default(0)->change();
            $table->decimal('estimated_total', 15, 2)->default(0)->change();
        });
    }
};
