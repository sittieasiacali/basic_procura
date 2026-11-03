<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('account_name')->nullable()->after('account_number');
        });

        Schema::create('procurement_sequences', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->unsignedBigInteger('current_value');
            $table->timestamps();
        });

        $highest = DB::table('purchase_orders')->pluck('po_number')->map(function ($number): int {
            return preg_match('/^ASI(\d{6})$/', (string) $number, $matches) ? (int) $matches[1] : 0;
        })->max();

        DB::table('procurement_sequences')->insert([
            'name' => 'purchase_order',
            'current_value' => max(4610, (int) $highest),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_sequences');
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('account_name');
        });
    }
};