<?php

use App\Models\InventoryItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only replace the previous system format (SKU-...). Do not change
        // older user-issued codes that may already be printed on labels.
        DB::table('inventory_items')->where('sku', 'like', 'SKU-%')->orderBy('id')
            ->chunkById(200, function ($items): void {
                foreach ($items as $item) {
                    DB::table('inventory_items')->where('id', $item->id)->where('sku', 'like', 'SKU-%')
                        ->update(['sku' => InventoryItem::nextSku(InventoryItem::prefixFor($item->purchase_request_id, $item->logged_by))]);
                }
            });
    }

    public function down(): void
    {
        // Do not roll back issued SKUs; they may already be used for scanning.
    }
};
