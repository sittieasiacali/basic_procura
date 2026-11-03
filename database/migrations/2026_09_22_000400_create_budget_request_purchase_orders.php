<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_request_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->unique(['budget_request_id', 'purchase_request_id'], 'br_po_request_unique');
        });

        Schema::table('budget_request_lines', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('budget_request_id')->constrained()->nullOnDelete();
        });

        DB::table('budget_requests')
            ->whereNotNull('purchase_order_id')
            ->orderBy('id')
            ->get()
            ->each(function ($budget): void {
                DB::table('budget_request_purchase_orders')->insert([
                    'budget_request_id' => $budget->id,
                    'purchase_request_id' => $budget->purchase_request_id,
                    'purchase_order_id' => $budget->purchase_order_id,
                    'amount' => $budget->amount,
                    'created_at' => $budget->created_at,
                    'updated_at' => $budget->updated_at,
                ]);
                DB::table('budget_request_lines')
                    ->where('budget_request_id', $budget->id)
                    ->whereNull('purchase_order_id')
                    ->update(['purchase_order_id' => $budget->purchase_order_id]);
            });
    }

    public function down(): void
    {
        Schema::table('budget_request_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });

        Schema::dropIfExists('budget_request_purchase_orders');
    }
};
