<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->unique();
            $table->foreignId('purchase_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->index();
            $table->string('invoice_number')->nullable()->index();
            $table->date('invoice_date')->nullable();
            $table->decimal('invoice_amount', 15, 2)->nullable();
            $table->string('status')->default('PARTIAL')->index();
            $table->string('delivery_note_number')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['purchase_order_id', 'received_at']);
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description');
            $table->decimal('quantity_received', 12, 2);
            $table->string('unit', 50);
            $table->string('condition')->default('GOOD');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('inventory_code')->unique();
            $table->string('asset_tag')->nullable()->unique();
            $table->foreignId('goods_receipt_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('logged_by')->constrained('users')->restrictOnDelete();
            $table->text('description');
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 50);
            $table->string('serial_number')->nullable()->index();
            $table->string('location')->nullable();
            $table->text('sticker_details')->nullable();
            $table->string('status')->default('LOGGED')->index();
            $table->timestamp('logged_at')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('endorsements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requestor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('endorsed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('endorsed_at')->index();
            $table->timestamp('received_by_requestor_at')->nullable()->index();
            $table->string('status')->default('PENDING_ACKNOWLEDGMENT')->index();
            $table->text('acknowledgment')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['purchase_request_id', 'requestor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endorsements');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
    }
};
