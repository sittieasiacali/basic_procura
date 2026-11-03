<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replace the random SKUs (IT-8PQ-G6U) and inventory codes (INV-20261028-UBTG)
 * with sequential ones (IT-00001, INV-20261028-0001), oldest item first.
 * Screening records that copied an old code are updated to match.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $skuCounters = [];
            $codeCounters = [];

            DB::table('inventory_items')->orderBy('created_at')->orderBy('id')->get()->each(function ($item) use (&$skuCounters, &$codeCounters): void {
                $prefix = str_starts_with((string) $item->sku, 'PREM-') ? 'PREM' : 'IT';
                $skuCounters[$prefix] = ($skuCounters[$prefix] ?? 0) + 1;
                $sku = $prefix.'-'.str_pad((string) $skuCounters[$prefix], 5, '0', STR_PAD_LEFT);

                $day = date('Ymd', strtotime((string) ($item->created_at ?? $item->logged_at ?? 'now')));
                $codeCounters[$day] = ($codeCounters[$day] ?? 0) + 1;
                $code = 'INV-'.$day.'-'.str_pad((string) $codeCounters[$day], 4, '0', STR_PAD_LEFT);

                // A temporary value first, so a new code never collides with one not yet renumbered.
                DB::table('inventory_items')->where('id', $item->id)->update(['sku' => 'TMP-'.$item->id, 'inventory_code' => 'TMP-'.$item->id]);
                $item->new_sku = $sku;
                $item->new_code = $code;
                $this->renumbered[] = $item;
            });

            foreach ($this->renumbered as $item) {
                DB::table('inventory_items')->where('id', $item->id)->update(['sku' => $item->new_sku, 'inventory_code' => $item->new_code]);

                if (filled($item->sku)) {
                    DB::table('screening_assets')->where('inventory_item_id', $item->id)->where('item_code', $item->sku)->update(['item_code' => $item->new_sku]);
                }
                DB::table('screening_assets')->where('inventory_item_id', $item->id)->where('asset_tag', $item->inventory_code)->update(['asset_tag' => $item->new_code]);
            }
        });
    }

    public function down(): void
    {
        // The random codes are not restored; the sequential ones may already be printed.
    }

    /** @var list<object> */
    private array $renumbered = [];
};
