<?php

namespace App\Support;

use App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class AdminRecordCatalog
{
    public static function models(): array
    {
        return [
            'purchase-requests' => Models\PurchaseRequest::class,
            'request-items' => Models\PurchaseRequestItem::class,
            'request-approvals' => Models\RequestApproval::class,
            'screenings' => Models\RequestScreening::class,
            'screening-assets' => Models\ScreeningAsset::class,
            'quotations' => Models\SupplierQuotation::class,
            'purchase-orders' => Models\PurchaseOrder::class,
            'purchase-order-items' => Models\PurchaseOrderItem::class,
            'purchase-order-approvals' => Models\PurchaseOrderApproval::class,
            'budget-requests' => Models\BudgetRequest::class,
            'budget-lines' => Models\BudgetRequestLine::class,
            'budget-allocations' => Models\BudgetRequestPurchaseOrder::class,
            'payments' => Models\Payment::class,
            'goods-receipts' => Models\GoodsReceipt::class,
            'goods-receipt-items' => Models\GoodsReceiptItem::class,
            'inventory-items' => Models\InventoryItem::class,
            'inventory-releases' => Models\InventoryRelease::class,
            'inventory-claim-proofs' => Models\InventoryClaimProof::class,
            'inventory-activities' => Models\InventoryActivity::class,
            'endorsements' => Models\Endorsement::class,
            'attachments' => Models\Attachment::class,
            'accounts' => Models\User::class,
            'campaigns' => Models\Campaign::class,
            'roles' => Models\Role::class,
            'po-role-assignments' => Models\PoRoleAssignment::class,
            'suppliers' => Models\Supplier::class,
            'delivery-locations' => Models\DeliveryLocation::class,
            'notifications' => Models\Notification::class,
            'audit-logs' => Models\AuditLog::class,
        ];
    }

    public static function model(string $type): Model
    {
        $class = self::models()[$type] ?? null;
        abort_unless($class, 404);

        return new $class;
    }

    public static function labels(): array
    {
        return collect(self::models())->map(fn ($class, $type) => Str::headline($type))->all();
    }

    public static function label(Model $record): string
    {
        foreach (['request_number', 'po_number', 'budget_request_number', 'name', 'title', 'description', 'original_name', 'action', 'sku'] as $field) {
            if (filled($value = $record->getRawOriginal($field))) {
                return Str::limit((string) $value, 120);
            }
        }

        return class_basename($record).' #'.$record->getKey();
    }
}
