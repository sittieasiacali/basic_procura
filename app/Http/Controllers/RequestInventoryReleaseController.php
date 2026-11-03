<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseRequestStatus;
use App\Models\InventoryItem;
use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestWorkflow;
use App\Support\WorkflowSignature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestInventoryReleaseController extends Controller
{
    public function store(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestWorkflow $workflow): RedirectResponse
    {
        $this->authorize('releaseInventory', $purchaseRequest);
        $validated = $request->validate([
            ...WorkflowSignature::rules(),
            'released_to' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'inventory' => ['required', 'array', 'min:1', 'max:100'],
            'inventory.*' => ['required', 'integer', 'exists:inventory_items,id'],
        ]);
        WorkflowSignature::assertValid($request, $validated);

        DB::transaction(function () use ($request, $purchaseRequest, $workflow, $validated): void {
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $this->authorize('releaseInventory', $locked);
            $assets = $locked->screening?->assets()->orderBy('id')->get();
            if (! $assets || $assets->isEmpty() || $assets->count() !== count($validated['inventory'])) {
                throw ValidationException::withMessages(['inventory' => 'Select inventory for every screened asset.']);
            }
            $quantities = [];
            foreach ($assets as $asset) {
                $inventoryId = $validated['inventory'][$asset->id] ?? null;
                if (! $inventoryId || (float) $asset->quantity <= 0) {
                    throw ValidationException::withMessages(['inventory' => 'Select inventory and a positive quantity for every screened asset.']);
                }
                $quantities[$inventoryId] = ($quantities[$inventoryId] ?? 0) + (float) $asset->quantity;
            }
            $stock = InventoryItem::query()->whereKey(array_keys($quantities))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($quantities as $id => $quantity) {
                $item = $stock->get($id);
                if (! $item || $item->status !== 'LOGGED' || (float) $item->quantity < $quantity) {
                    throw ValidationException::withMessages(['inventory' => 'A selected inventory item no longer has enough available stock.']);
                }
            }
            foreach ($assets as $asset) {
                $item = $stock->get($validated['inventory'][$asset->id]);
                $release = $item->releases()->create([
                    'released_by' => $request->user()->id,
                    'recipient_user_id' => $validated['released_to'] === $locked->requestor?->name ? $locked->requestor_id : null,
                    'quantity' => $asset->quantity,
                    'released_to' => $validated['released_to'],
                    'remarks' => 'Request '.$locked->request_number.': '.($validated['remarks'] ?? 'Released from available stock.'),
                    'released_at' => now(),
                    'signature_name' => $validated['signature_name'],
                    'signature_data' => $validated['signature_data'],
                ]);
                $item->activities()->create([
                    'inventory_release_id' => $release->id,
                    'actor_id' => $request->user()->id,
                    'action' => 'RELEASED',
                    'quantity' => $asset->quantity,
                    'occurred_at' => $release->released_at,
                ]);
                $remaining = round((float) $item->quantity - (float) $asset->quantity, 2);
                $item->update(['quantity' => $remaining, 'status' => $remaining > 0 ? 'LOGGED' : 'RELEASED']);
                $asset->update([
                    'inventory_item_id' => $item->id,
                    'asset_tag' => $item->asset_tag ?: $item->inventory_code,
                    'item_code' => $item->sku ?: $item->inventory_code,
                    'description' => $item->description,
                    'serial_number' => $item->serial_number,
                    'location' => $item->location,
                    'released_by' => $request->user()->id,
                    'release_date' => now()->toDateString(),
                    'recipient' => $validated['released_to'],
                ]);
                $locked->endorsements()->create([
                    'inventory_item_id' => $item->id,
                    'requestor_id' => $locked->requestor_id,
                    'endorsed_by' => $request->user()->id,
                    'endorsed_at' => now(),
                    'status' => 'PROVIDED',
                    'remarks' => $validated['remarks'] ?? 'Provided from available inventory.',
                ]);
            }
            $workflow->transition($locked, PurchaseRequestStatus::Fulfilled, $request->user(), 'INVENTORY_RELEASED',
                $validated['remarks'] ?? 'Released available stock to '.$validated['released_to'].'.', 'INVENTORY_RELEASE',
                [], $validated['signature_name'], $validated['signature_data']);
        });

        return redirect()->route('purchase-requests.show', $purchaseRequest)->with('success', 'Inventory released and request fulfilled.');
    }
}
