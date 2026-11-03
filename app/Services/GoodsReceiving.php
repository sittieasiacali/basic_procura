<?php

namespace App\Services;

use App\Enums\PurchaseRequestStatus;
use App\Models\InventoryItem;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Support\WorkflowSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Records goods for a request at For Receiving: a signed receipt, one receipt
 * line and inventory entry per item, and the hand-off to Completed. PO items
 * are used when present; legacy requests use their request items instead.
 */
class GoodsReceiving
{
    public function __construct(private readonly PurchaseRequestWorkflow $workflow)
    {
    }

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'serial_numbers' => ['nullable', 'array'],
            'serial_numbers.*' => ['nullable', 'string', 'max:255'],
            // Per line, keyed like serial_numbers: what actually arrived and where it is kept.
            // Blank values fall back to the PO quantity / unit and the request's delivery location.
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'units' => ['nullable', 'array'],
            'units.*' => ['nullable', 'string', 'max:50'],
            'locations' => ['nullable', 'array'],
            'locations.*' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            ...WorkflowSignature::rules(),
        ];
    }

    /**
     * The lines that will be received, keyed by a stable line key: PO items when the
     * PO has them, otherwise the request items.
     *
     * @return Collection<string, array{key: string, purchase_order_item_id: int|null, description: string, quantity: float, unit: string}>
     */
    public static function lines(PurchaseRequest $purchaseRequest): Collection
    {
        $orderItems = $purchaseRequest->purchaseOrder?->items ?? collect();

        $lines = $orderItems->isNotEmpty()
            ? $orderItems->map(fn ($item) => [
                'key' => 'po-'.$item->id,
                'purchase_order_item_id' => $item->id,
                'description' => (string) $item->description,
                'quantity' => (float) $item->quantity,
                'unit' => (string) ($item->unit ?: 'unit'),
            ])
            : $purchaseRequest->items->map(fn ($item) => [
                'key' => 'pr-'.$item->id,
                'purchase_order_item_id' => null,
                'description' => (string) $item->description,
                'quantity' => (float) $item->quantity,
                'unit' => (string) ($item->unit ?: 'unit'),
            ]);

        if ($lines->isEmpty()) {
            $lines = collect([[
                'key' => 'request',
                'purchase_order_item_id' => null,
                'description' => (string) $purchaseRequest->title,
                'quantity' => 1.0,
                'unit' => 'lot',
            ]]);
        }

        return $lines->keyBy('key');
    }

    /** Expects a request locked for update and already in FOR_RECEIVING. */
    public function receive(Request $request, PurchaseRequest $lockedRequest, User $user, array $validated): Collection
    {
        if ($lockedRequest->status !== PurchaseRequestStatus::ForReceiving) {
            throw ValidationException::withMessages(['status' => 'This request is not ready to receive goods yet.']);
        }

        $purchaseOrder = $lockedRequest->purchaseOrder()->with('items')->first();
        WorkflowSignature::assertValid($request, $validated);
        $lockedRequest->setRelation('purchaseOrder', $purchaseOrder)->loadMissing('items');
        $serials = $validated['serial_numbers'] ?? [];
        $quantities = $validated['quantities'] ?? [];
        $units = $validated['units'] ?? [];
        $locations = $validated['locations'] ?? [];

        $receipt = $lockedRequest->goodsReceipts()->where('status', 'RECEIVED')->latest('id')->first();
        if ($receipt) {
            // Older requests may already have a receipt while its inventory rows
            // were never created. Complete that receipt instead of duplicating it.
            $receipt->update([
                'received_by' => $user->id,
                'received_at' => now(),
                'remarks' => $validated['remarks'] ?? $receipt->remarks,
                'signature_name' => $validated['signature_name'],
                'signature_data' => $validated['signature_data'],
            ]);
        } else {
            $receipt = $lockedRequest->goodsReceipts()->create([
                'receipt_number' => 'GR-'.now()->format('YmdHis').'-'.$lockedRequest->id,
                'purchase_order_id' => $purchaseOrder?->id,
                'received_by' => $user->id,
                'received_at' => now(),
                'status' => 'RECEIVED',
                'remarks' => $validated['remarks'] ?? null,
                'signature_name' => $validated['signature_name'],
                'signature_data' => $validated['signature_data'],
            ]);
        }

        $existingLines = $receipt->items()->with('inventoryItems')->get();
        $usedLineIds = [];
        $inventoryItems = self::lines($lockedRequest)->map(function (array $line) use ($receipt, $lockedRequest, $user, $serials, $quantities, $units, $locations, $validated, $existingLines, &$usedLineIds) {
            $serial = trim((string) ($serials[$line['key']] ?? '')) ?: null;
            $line['quantity'] = is_numeric($quantities[$line['key']] ?? null) ? (float) $quantities[$line['key']] : $line['quantity'];
            $line['unit'] = trim((string) ($units[$line['key']] ?? '')) ?: $line['unit'];
            $location = trim((string) ($locations[$line['key']] ?? '')) ?: $lockedRequest->delivery_location;
            $receiptItem = $existingLines->first(fn ($item) => ! in_array($item->id, $usedLineIds, true)
                && ($line['purchase_order_item_id'] !== null
                    ? (int) $item->purchase_order_item_id === (int) $line['purchase_order_item_id']
                    : $item->purchase_order_item_id === null && $item->description === $line['description']));
            if ($receiptItem) {
                $usedLineIds[] = $receiptItem->id;
            } else {
                $receiptItem = $receipt->items()->create([
                    'purchase_order_item_id' => $line['purchase_order_item_id'],
                    'description' => $line['description'],
                    'quantity_received' => $line['quantity'],
                    'unit' => $line['unit'],
                    'condition' => 'GOOD',
                    'remarks' => $validated['remarks'] ?? null,
                ]);
            }

            if ($receiptItem->inventoryItems()->exists()) {
                return $receiptItem->inventoryItems()->first();
            }

            return InventoryItem::query()->create([
                'inventory_code' => InventoryItem::nextCode(),
                'asset_tag' => $serial,
                'goods_receipt_item_id' => $receiptItem->id,
                'purchase_request_id' => $lockedRequest->id,
                'logged_by' => $user->id,
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'],
                'serial_number' => $serial,
                'location' => $location,
                'status' => 'LOGGED',
                'logged_at' => now(),
            ]);
        })->values();

        $this->workflow->transition(
            $lockedRequest,
            PurchaseRequestStatus::Completed,
            $user,
            'RECEIVED_AND_INVENTORY_UPDATED',
            'Goods received and inventory updated.',
            'RECEIVING',
            [],
            $validated['signature_name'],
            $validated['signature_data'],
        );

        return $inventoryItems;
    }
}
