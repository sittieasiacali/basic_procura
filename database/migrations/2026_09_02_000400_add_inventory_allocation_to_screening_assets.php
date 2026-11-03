<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screening_assets', function (Blueprint $table) {
            $table->foreignId('inventory_item_id')->nullable()->after('request_screening_id')->constrained()->nullOnDelete();
            $table->decimal('quantity', 12, 2)->default(1)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('screening_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_item_id');
            $table->dropColumn('quantity');
        });
    }
};
