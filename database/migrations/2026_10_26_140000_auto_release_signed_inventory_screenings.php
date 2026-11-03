<?php

use App\Enums\PurchaseRequestStatus;
use App\Models\InventoryItem;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\PurchaseRequestWorkflow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        PurchaseRequest::query()
            ->where('status', PurchaseRequestStatus::ForInventoryRelease->value)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id): void {
                DB::transaction(function () use ($id): void {
                    $request = PurchaseRequest::query()->lockForUpdate()->find($id);
                    if (! $request || $request->status !== PurchaseRequestStatus::ForInventoryRelease) {
                        return;
                    }

                    $screening = $request->screening;
                    $actor = $screening ? User::query()->find($screening->screened_by) : null;
                    $assets = $screening?->assets()->orderBy('id')->get();
                    if (! $actor || ! $assets || $assets->isEmpty()
                        || ! $request->approvals()->where('action', 'INVENTORY_AVAILABLE')->whereNotNull('signature_data')->exists()
                        || $assets->contains(fn ($asset) => ! $asset->inventory_item_id)) {
                        return;
                    }

                    $quantities = $assets->groupBy('inventory_item_id')
                        ->map(fn ($rows) => (float) $rows->sum('quantity'));
                    $stock = InventoryItem::query()->whereKey($quantities->keys())->orderBy('id')
                        ->lockForUpdate()->get()->keyBy('id');
                    foreach ($quantities as $stockId => $quantity) {
                        $item = $stock->get((int) $stockId);
                        if (! $item || $item->status !== 'LOGGED' || (float) $item->quantity < $quantity) {
                            return;
                        }
                    }

                    foreach ($assets as $asset) {
                        $item = $stock->get($asset->inventory_item_id);
                        $item->releases()->create([
                            'released_by' => $actor->id,
                            'quantity' => $asset->quantity,
                            'released_to' => $asset->recipient,
                            'remarks' => 'Automatically released for request '.$request->request_number.'.',
                            'released_at' => now(),
                        ]);
                        $remaining = round((float) $item->quantity - (float) $asset->quantity, 2);
                        $item->update(['quantity' => $remaining, 'status' => $remaining > 0 ? 'LOGGED' : 'RELEASED']);
                        $asset->update(['released_by' => $actor->id, 'release_date' => now()->toDateString()]);
                        $request->endorsements()->create([
                            'inventory_item_id' => $item->id,
                            'requestor_id' => $request->requestor_id,
                            'endorsed_by' => $actor->id,
                            'endorsed_at' => now(),
                            'status' => 'PROVIDED',
                            'remarks' => 'Provided from available inventory.',
                        ]);
                    }

                    app(PurchaseRequestWorkflow::class)->transition(
                        $request, PurchaseRequestStatus::Fulfilled, $actor, 'INVENTORY_RELEASED',
                        'Stock released automatically from the signed inventory screening.',
                    );
                });
            });
    }

    public function down(): void
    {
        // Stock movements are business records and cannot safely be reversed by a schema rollback.
    }
};
