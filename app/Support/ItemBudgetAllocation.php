<?php

namespace App\Support;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SupplierQuotation;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ItemBudgetAllocation
{
    public static function rules(): array
    {
        return [
            'item_allocations' => ['sometimes', 'required', 'array'],
            'item_allocations.*' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
        ];
    }

    public static function forRequest(PurchaseRequest $request, ?array $submitted = null): array
    {
        $quotes = SupplierQuotation::deduplicateForReview($request->quotations()
            ->where('supplier_id', $request->selected_supplier_id)->where('is_approved', true)->get());
        $total = (float) $quotes->sum(fn ($quote) => $quote->vatBreakdown()['total']);
        $shipping = (float) $quotes->sum('shipping_fee');
        $weights = PurchaseOrderLinePricing::amounts($request->items, $quotes);

        return self::allocate($request->items, $total, $shipping, $submitted, $weights);
    }

    public static function forOrder(PurchaseOrder $order, ?array $submitted = null): array
    {
        if ($submitted === null && $order->item_budget_allocations) {
            return $order->item_budget_allocations;
        }
        $request = $order->purchaseRequest;
        $quotes = SupplierQuotation::deduplicateForReview($request->quotations()
            ->where('supplier_id', $order->supplier_id)->where('is_approved', true)->get());
        $shipping = $order->item_budget_allocations
            ? (float) collect($order->item_budget_allocations)->sum('shipping_fee')
            : (float) $quotes->sum('shipping_fee');
        $weights = $order->items->mapWithKeys(fn ($item) => [$item->purchase_request_item_id => (float) $item->total_price])->all();

        return self::allocate($request->items, (float) $order->total_amount, $shipping, $submitted, $weights);
    }

    /** All calculations distribute integer cents, including rounding remainders. */
    public static function allocate(Collection $items, float $total, float $shipping, ?array $submitted = null, array $weights = []): array
    {
        if ($items->isEmpty()) {
            if ($submitted !== null) {
                throw ValidationException::withMessages(['item_allocations' => 'This request has no items to allocate.']);
            }

            return [];
        }
        $totalCents = (int) round($total * 100);
        $shippingCents = (int) round($shipping * 100);
        if ($shippingCents < 0 || $shippingCents > $totalCents) {
            throw ValidationException::withMessages(['item_allocations' => 'Shipping must be within the approved total.']);
        }
        $ids = $items->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
        if ($submitted !== null && $ids !== collect(array_keys($submitted))->map(fn ($id) => (string) $id)->sort()->values()->all()) {
            throw ValidationException::withMessages(['item_allocations' => 'Allocate the budget to every item in this request, without additional items.']);
        }
        $weights = $items->mapWithKeys(fn ($item) => [$item->id => max(0, $weights[$item->id] ?? (float) $item->estimated_total)])->all();
        $base = $submitted === null ? self::distribute($totalCents - $shippingCents, $weights)
            : array_map(fn ($value) => (int) round((float) $value * 100), $submitted);
        if (min($base) < 0 || array_sum($base) !== $totalCents - $shippingCents) {
            throw ValidationException::withMessages(['item_allocations' => 'Item budgets must equal the approved total excluding shipping. Shipping is distributed automatically.']);
        }
        $shares = self::distribute($shippingCents, $base);

        return $items->map(fn ($item) => [
            'purchase_request_item_id' => $item->id,
            'description' => $item->description,
            'amount' => number_format($base[$item->id] / 100, 2, '.', ''),
            'shipping_fee' => number_format($shares[$item->id] / 100, 2, '.', ''),
            'total' => number_format(($base[$item->id] + $shares[$item->id]) / 100, 2, '.', ''),
        ])->values()->all();
    }

    private static function distribute(int $cents, array $weights): array
    {
        if (array_sum($weights) <= 0) {
            $weights = array_fill_keys(array_keys($weights), 1);
        }
        $sum = array_sum($weights);
        $result = $fractions = [];
        foreach ($weights as $id => $weight) {
            $share = $cents * $weight / $sum;
            $result[$id] = (int) floor($share);
            $fractions[$id] = $share - $result[$id];
        }
        arsort($fractions, SORT_NUMERIC);
        foreach (array_slice(array_keys($fractions), 0, $cents - array_sum($result)) as $id) {
            $result[$id]++;
        }

        return $result;
    }
}
