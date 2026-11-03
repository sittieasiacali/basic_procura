<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_releases', function (Blueprint $table) {
            $table->text('claim_remarks')->nullable();
            $table->string('claim_signature_name')->nullable();
            $table->longText('claim_signature_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_releases', function (Blueprint $table) {
            $table->dropColumn(['claim_remarks', 'claim_signature_name', 'claim_signature_data']);
        });
    }
};
