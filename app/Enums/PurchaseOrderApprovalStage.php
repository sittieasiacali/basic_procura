<?php

namespace App\Enums;

enum PurchaseOrderApprovalStage: string
{
    case PreparedBy = 'PO_PREPARED_BY';
    case CheckedBy = 'PO_CHECKED_BY';
    case NotedBy = 'PO_NOTED_BY';
    case ApprovedBy = 'PO_APPROVED_BY';

    public function roleKey(): string
    {
        return match ($this) {
            self::PreparedBy => 'po_preparer',
            self::CheckedBy => 'po_checker',
            self::NotedBy => 'po_noter',
            self::ApprovedBy => 'po_approver',
        };
    }
}