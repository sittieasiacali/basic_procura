<?php

namespace App\Support;

use App\Models\PurchaseRequestItem;
use App\Models\SupplierQuotation;
use Illuminate\Support\Collection;

class PurchaseOrderLinePricing
{
    /**
     * Allocate the approved quotation's net amount to the requested items.
     * Item-specific quotations keep their exact net amounts; whole-request
     * quotations are distributed by the request estimates, then by item count.
     *
     * @param Collection<int, PurchaseRequestItem> $items
     * @param Collection<int, SupplierQuotation> $quotations
     * @return array<int, float>
     */
    public static function amounts(Collection $items, Collection $quotations): array
    {
        $cents = $items->mapWithKeys(fn (PurchaseRequestItem $item) => [$item->id => 0])->all();
        if ($cents === []) {
            return [];
        }

        $unallocated = 0;
        foreach ($quotations as $quotation) {
            $amount = (int) round($quotation->vatBreakdown()['base'] * 100);
            $itemId = $quotation->purchase_request_item_id;
            if ($itemId !== null && array_key_exists($itemId, $cents)) {
                $cents[$itemId] += $amount;
            } else {
                $unallocated += $amount;
            }
        }

        if ($unallocated > 0) {
            $weights = $items->mapWithKeys(function (PurchaseRequestItem $item): array {
                $estimate = (float) ($item->estimated_total
                    ?? ((float) $item->quantity * (float) $item->estimated_unit_price));

                return [$item->id => $estimate > 0 ? $estimate : 1];
            })->all();
            $weightTotal = array_sum($weights);
            $fractions = [];
            $distributed = 0;

            foreach ($weights as $itemId => $weight) {
                $share = $unallocated * $weight / $weightTotal;
                $wholeCents = (int) floor($share);
                $cents[$itemId] += $wholeCents;
                $distributed += $wholeCents;
                $fractions[$itemId] = $share - $wholeCents;
            }

            arsort($fractions, SORT_NUMERIC);
            foreach (array_slice(array_keys($fractions), 0, $unallocated - $distributed) as $itemId) {
                $cents[$itemId]++;
            }
        }

        return array_map(fn (int $amount) => $amount / 100, $cents);
    }

    public static function unitPrice(float $lineAmount, float $quantity): float
    {
        return $quantity > 0 ? round($lineAmount / $quantity, 2) : 0;
    }
}
