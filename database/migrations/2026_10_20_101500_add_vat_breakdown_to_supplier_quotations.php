<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_quotations', function (Blueprint $table): void {
            $table->string('vat_treatment', 20)->nullable()->after('price');
            $table->decimal('base_amount', 15, 2)->nullable()->after('vat_treatment');
            $table->decimal('vat_rate', 5, 2)->nullable()->after('base_amount');
            $table->decimal('vat_amount', 15, 2)->nullable()->after('vat_rate');
            $table->decimal('total_amount', 15, 2)->nullable()->after('vat_amount');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_quotations', function (Blueprint $table): void {
            $table->dropColumn(['vat_treatment', 'base_amount', 'vat_rate', 'vat_amount', 'total_amount']);
        });
    }
};