<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The person receiving the goods signs the receipt.
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->string('signature_name')->nullable()->after('remarks');
            $table->longText('signature_data')->nullable()->after('signature_name');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn(['signature_name', 'signature_data']);
        });
    }
};
