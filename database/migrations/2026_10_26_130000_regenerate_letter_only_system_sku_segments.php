<?php

use App\Models\InventoryItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('inventory_items')
            ->where(fn ($query) => $query->where('sku', 'like', 'IT-%')->orWhere('sku', 'like', 'PREM-%'))
            ->orderBy('id')
            ->chunkById(200, function ($items): void {
                foreach ($items as $item) {
                    $segments = explode('-', (string) $item->sku);
                    $prefix = $segments[0] ?? '';
                    $hasMixedSegments = count($segments) === 3
                        && in_array($prefix, ['IT', 'PREM'], true)
                        && preg_match('/\d/', $segments[1]) === 1
                        && preg_match('/\d/', $segments[2]) === 1;

                    if ($hasMixedSegments) {
                        continue;
                    }

                    DB::table('inventory_items')->where('id', $item->id)->where('sku', $item->sku)
                        ->update(['sku' => InventoryItem::nextSku($prefix)]);
                }
            });
    }

    public function down(): void
    {
        // Do not restore old identifiers after they have been issued for scanning.
    }
};
