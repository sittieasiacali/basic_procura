<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Finance can add stock that did not arrive through a purchase, so the
        // receipt and request links become optional.
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->foreignId('goods_receipt_item_id')->nullable()->change();
            $table->foreignId('purchase_request_id')->nullable()->change();
        });

        Schema::create('inventory_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('released_by')->constrained('users')->restrictOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->string('released_to');
            $table->text('remarks')->nullable();
            $table->timestamp('released_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_releases');

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->foreignId('goods_receipt_item_id')->nullable(false)->change();
            $table->foreignId('purchase_request_id')->nullable(false)->change();
        });
    }
};
