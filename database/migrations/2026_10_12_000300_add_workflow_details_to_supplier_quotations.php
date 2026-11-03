<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_quotations', function (Blueprint $table): void {
            $table->string('delivery_location')->nullable()->after('delivery_lead_time');
            $table->foreignId('recommended_by')->nullable()->after('is_recommended')->constrained('users')->nullOnDelete();
            $table->timestamp('recommended_at')->nullable()->after('recommended_by');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_quotations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recommended_by');
            $table->dropColumn(['delivery_location', 'recommended_at']);
        });
    }
};
