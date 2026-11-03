<?php

use App\Models\InventoryItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('inventory_items')
            ->where(fn ($query) => $query->whereNull('sku')->orWhereRaw("TRIM(sku) = ''"))
            ->orderBy('id')
            ->chunkById(200, function ($items): void {
                foreach ($items as $item) {
                    DB::table('inventory_items')->where('id', $item->id)
                        ->where(fn ($query) => $query->whereNull('sku')->orWhereRaw("TRIM(sku) = ''"))
                        ->update(['sku' => InventoryItem::nextSku(InventoryItem::prefixFor($item->purchase_request_id, $item->logged_by))]);
                }
            });
    }

    public function down(): void
    {
        // Keep issued SKUs stable because they can appear on labels and releases.
    }
};
