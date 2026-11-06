<?php

namespace App\Services;

use App\Models;
use App\Support\AdminRecordCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class AdminTransactionRecords
{
    /** Collect dependent records before their parents, without following account/setup references. */
    public function collect(Models\PurchaseRequest $request, bool $lock = false): Collection
    {
        $excluded = ['accounts', 'roles', 'campaigns', 'suppliers', 'delivery-locations', 'notifications', 'audit-logs', 'po-role-assignments'];
        $dependencies = [];
        foreach (AdminRecordCatalog::models() as $type => $class) {
            if (in_array($type, $excluded, true)) {
                continue;
            }
            foreach (Schema::getForeignKeys((new $class)->getTable()) as $key) {
                $dependencies[$key['foreign_table']][] = [$class, $key];
            }
        }

        $records = collect();
        $visited = [];
        $visit = function (Model $record) use (&$visit, &$visited, $records, $dependencies, $request, $lock): void {
            $key = $record::class.':'.$record->getKey();
            if (isset($visited[$key])) {
                return;
            }
            $visited[$key] = true;
            $this->assertOwnedByRequest($record, $request);
            foreach ($dependencies[$record->getTable()] ?? [] as [$class, $foreignKey]) {
                $query = $this->query($class, $lock);
                foreach ($foreignKey['columns'] as $index => $column) {
                    $query->where($column, $record->getAttribute($foreignKey['foreign_columns'][$index]));
                }
                foreach ($query->orderBy((new $class)->getKeyName())->get() as $child) {
                    $visit($child);
                }
            }
            if (! $record instanceof Models\Attachment) {
                foreach ($this->query(Models\Attachment::class, $lock)->whereMorphedTo('attachable', $record)->orderBy('id')->get() as $attachment) {
                    $visit($attachment);
                }
            }
            $records->push($record);
        };

        // Group budgets are reached through allocations, rather than a direct PR foreign key.
        $budgetIds = Models\BudgetRequestPurchaseOrder::where('purchase_request_id', $request->id)->pluck('budget_request_id');
        foreach ($this->query(Models\BudgetRequest::class, $lock)->whereIn('id', $budgetIds)->orderBy('id')->get() as $budget) {
            $visit($budget);
        }
        $visit($request);

        // Earlier screening releases identify their request in these system-generated remarks.
        $stockIds = DB::table('screening_assets')->join('request_screenings', 'request_screenings.id', '=', 'screening_assets.request_screening_id')
            ->where('request_screenings.purchase_request_id', $request->id)->pluck('screening_assets.inventory_item_id')->filter();
        $prefix = 'Request '.$request->request_number.': ';
        foreach ($this->query(Models\InventoryRelease::class, $lock)->whereIn('inventory_item_id', $stockIds)
            ->where(fn ($query) => $query->where('remarks', 'Automatically released for request '.$request->request_number.'.')
                ->orWhereRaw('SUBSTR(remarks, 1, ?) = ?', [mb_strlen($prefix), $prefix]))->orderBy('id')->get() as $release) {
            $visit($release);
        }

        return $records;
    }

    public function stockRestorations(Collection $records): Collection
    {
        $ownedStockIds = $records->filter(fn ($record) => $record instanceof Models\InventoryItem)->pluck('id');

        return $records->filter(fn ($record) => $record instanceof Models\InventoryRelease && ! $ownedStockIds->contains($record->inventory_item_id))
            ->groupBy('inventory_item_id')->map(fn ($releases, $id) => ['inventory_item_id' => (int) $id, 'quantity' => round((float) $releases->sum('quantity'), 2)])->values();
    }

    public function token(Collection $records): string
    {
        return hash('sha256', $records->map(fn (Model $record) => [
            'key' => $record::class.':'.$record->getKey(), 'attributes' => $record->getAttributes(),
        ])->sortBy('key')->values()->toJson());
    }

    public function restoreStock(Collection $restorations, Models\User $actor): void
    {
        foreach ($restorations->sortBy('inventory_item_id') as $restoration) {
            $stock = Models\InventoryItem::query()->lockForUpdate()->find($restoration['inventory_item_id']);
            if (! $stock) {
                throw ValidationException::withMessages(['record' => 'A stock item linked to this transaction is unavailable. Reconcile its inventory release before deleting.']);
            }
            $stock->update(['quantity' => round((float) $stock->quantity + $restoration['quantity'], 2), 'status' => 'LOGGED']);
            $stock->activities()->create([
                'actor_id' => $actor->id, 'action' => 'RELEASE_REVERSED',
                'quantity' => $restoration['quantity'], 'occurred_at' => now(),
            ]);
        }
    }

    private function query(string $class, bool $lock): Builder
    {
        $query = $class::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query->withTrashed();
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query;
    }

    private function assertOwnedByRequest(Model $record, Models\PurchaseRequest $request): void
    {
        $ownerId = $record->getAttribute('purchase_request_id');
        foreach ([
            Models\ScreeningAsset::class => ['request_screenings', 'request_screening_id'],
            Models\GoodsReceiptItem::class => ['goods_receipts', 'goods_receipt_id'],
            Models\PurchaseOrderItem::class => ['purchase_orders', 'purchase_order_id'],
            Models\PurchaseOrderApproval::class => ['purchase_orders', 'purchase_order_id'],
            Models\BudgetRequestLine::class => ['purchase_orders', 'purchase_order_id'],
        ] as $class => [$table, $key]) {
            if ($record instanceof $class && $record->getAttribute($key)) {
                $ownerId = DB::table($table)->where('id', $record->getAttribute($key))->value('purchase_request_id');
            }
        }
        if ($ownerId !== null && (int) $ownerId !== $request->id) {
            throw ValidationException::withMessages(['record' => 'This transaction shares records with another purchase request. Separate those shared records before deleting the transaction.']);
        }
        if ($record instanceof Models\BudgetRequest && Models\BudgetRequestPurchaseOrder::where('budget_request_id', $record->id)->where('purchase_request_id', '!=', $request->id)->exists()) {
            throw ValidationException::withMessages(['record' => 'This request belongs to a shared Budget Request. Separate the group before deleting this transaction.']);
        }
    }
}
