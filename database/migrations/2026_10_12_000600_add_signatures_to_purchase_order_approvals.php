<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_approvals', function (Blueprint $table) {
            $table->string('signature_name')->nullable()->after('remarks');
            $table->longText('signature_data')->nullable()->after('signature_name');
            $table->timestamp('signed_at')->nullable()->after('signature_data');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_approvals', function (Blueprint $table) {
            $table->dropColumn(['signature_name', 'signature_data', 'signed_at']);
        });
    }
};
