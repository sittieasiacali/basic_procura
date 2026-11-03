<?php

namespace App\Support;

use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PurchaseOrderNumber
{
    /** Reserve one company PO number inside the caller's database transaction. */
    public static function reserve(): string
    {
        $settings = config('procurement.purchase_order_number');
        $prefix = (string) ($settings['prefix'] ?? 'ASI');
        $padding = (int) ($settings['padding'] ?? 6);
        $sequence = DB::table('procurement_sequences')->where('name', 'purchase_order')->lockForUpdate()->first();

        if (! $sequence) {
            throw new RuntimeException('The Purchase Order number sequence is not configured.');
        }

        $value = (int) $sequence->current_value;
        do {
            $value++;
            $number = $prefix.str_pad((string) $value, $padding, '0', STR_PAD_LEFT);
        } while (PurchaseOrder::withTrashed()->where('po_number', $number)->exists());

        DB::table('procurement_sequences')->where('name', 'purchase_order')->update([
            'current_value' => $value,
            'updated_at' => now(),
        ]);

        return $number;
    }
}