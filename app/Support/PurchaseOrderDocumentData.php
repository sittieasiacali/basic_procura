<?php

namespace App\Support;

use App\Enums\PurchaseOrderApprovalStage;
use App\Models\PurchaseRequest;
use App\Models\SupplierQuotation;

final class PurchaseOrderDocumentData
{
    /** @return array<string, mixed> */
    public static function from(PurchaseRequest $purchaseRequest): array
    {
        $purchaseRequest->loadMissing([
            'requestor',
            'teamLeader',
            'budgetRequest',
            'quotations.supplier',
            'quotations.item',
            'purchaseOrder.supplier',
            'purchaseOrder.creator.role',
            'purchaseOrder.checker.role',
            'purchaseOrder.acknowledger.role',
            'purchaseOrder.approver.role',
            'purchaseOrder.budgetCampaign',
            'purchaseOrder.items.requestItem',
            'purchaseOrder.budgetRequest',
            'purchaseOrder.approvals.assignedUser',
            'purchaseOrder.approvals.actor',
        ]);

        $purchaseOrder = $purchaseRequest->purchaseOrder;
        abort_unless($purchaseOrder, 404);

        $poSignatory = static function (PurchaseOrderApprovalStage $stage, string $label, $legacyUser, $legacyDate) use ($purchaseOrder): array {
            $approval = $purchaseOrder->approvalFor($stage);
            $actor = $approval?->acted_at ? $approval->actor : null;

            return [
                'label' => $label,
                'name' => $approval?->signature_name ?? $actor?->name ?? $legacyUser?->name ?? 'Not recorded',
                'role' => $label,
                'date' => $approval?->acted_at ?? $legacyDate,
                'signature' => WorkflowSignature::forDisplay($approval?->signature_data),
                'signedAt' => $approval?->signed_at,
            ];
        };
        $poSignatories = [
            $poSignatory(PurchaseOrderApprovalStage::PreparedBy, 'Prepared by', $purchaseOrder->creator, $purchaseOrder->created_at),
            $poSignatory(PurchaseOrderApprovalStage::CheckedBy, 'Checked by', $purchaseOrder->checker, $purchaseOrder->checked_at),
            $poSignatory(PurchaseOrderApprovalStage::NotedBy, 'Noted by', $purchaseOrder->acknowledger, $purchaseOrder->acknowledged_at),
            $poSignatory(PurchaseOrderApprovalStage::ApprovedBy, 'Approved by', $purchaseOrder->approver, $purchaseOrder->approved_at),
        ];

        $approvedQuotations = SupplierQuotation::deduplicateForReview(
            $purchaseRequest->quotations
                ->where('supplier_id', $purchaseOrder->supplier_id)
                ->where('is_approved', true)
                ->sortBy('id')
                ->values(),
        );
        $fallbackBankDetails = $approvedQuotations
            ->pluck('bank_details')
            ->first(fn ($details) => filled($details));
        $budgetRequest = $purchaseOrder->budgetRequest ?? $purchaseRequest->budgetRequest;
        $classification = filled($budgetRequest?->account_type)
            ? $budgetRequest->account_type
            : $purchaseOrder->budget_account_type;
        $classificationLabel = static fn ($value) => match ($value) {
            'ACA' => 'ACA',
            'OTHER_PARTY' => 'Other Party',
            'OWN_ACCOUNT' => 'Own Account',
            default => filled($value) ? (string) $value : 'Not recorded',
        };
        // BR classification is authoritative; retain a saved legacy PO classification.
        $accountType = filled($classification)
            ? $classificationLabel($classification)
            : '';
        $requestedBy = $purchaseRequest->teamLeader?->name
            ?? $purchaseRequest->requestor?->name
            ?? $purchaseOrder->requested_by
            ?? 'Not recorded';
        $sourceBankAccount = $purchaseOrder->budget_campaign_account_number
            ?: $purchaseOrder->budgetCampaign?->account_number;

        $items = $purchaseOrder->items->values()->map(function ($item, int $index) use ($approvedQuotations, $fallbackBankDetails, $requestedBy, $accountType, $purchaseOrder, $purchaseRequest): array {
            $quotation = $approvedQuotations->firstWhere('purchase_request_item_id', $item->purchase_request_item_id);

            return [
                'line_number' => $index + 1,
                'details' => $item->description,
                'specifications' => $item->specifications,
                'requested_by' => $requestedBy,
                'account_type' => $accountType,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'unit_price' => (float) $item->unit_price,
                'amount' => (float) $item->total_price,
                'item_code' => $purchaseOrder->po_number.' - '.($index + 1),
                'campaign' => $purchaseOrder->budgetCampaign?->name ?? $purchaseRequest->department_campaign,
                'bank_details' => $quotation?->bank_details ?: $fallbackBankDetails,
            ];
        });

        return [
            'purchaseOrder' => $purchaseOrder,
            'purchaseRequest' => $purchaseRequest,
            'poNumber' => $purchaseOrder->po_number,
            'issueDate' => $purchaseOrder->issued_at,
            'statusLabel' => str($purchaseOrder->status)->replace('_', ' ')->title()->toString(),
            'supplierName' => $purchaseOrder->supplier?->name ?? 'Unavailable supplier',
            'transactionTypeLabel' => match ($purchaseOrder->budget_transaction_type) {
                'ONLINE' => 'Online Transaction',
                'CASH' => 'Cash Transaction',
                'CHECK' => 'Check Transaction',
                default => filled($purchaseOrder->budget_transaction_type)
                    ? str($purchaseOrder->budget_transaction_type)->replace('_', ' ')->title()->toString()
                    : 'Not recorded',
            },
            'sourceBankAccount' => $sourceBankAccount ?: 'Not configured',
            'accountClassification' => [
                'value' => $classification,
                'label' => $classificationLabel($classification),
                'budgetRequestNumber' => $budgetRequest?->budget_request_number,
                'isLegacy' => blank($budgetRequest?->account_type) && filled($classification),
                'mainFund' => $budgetRequest?->main_fund ?? $purchaseOrder->main_fund,
            ],
            'campaignBankAccount' => [
                'campaignName' => $purchaseOrder->budgetCampaign?->name,
                'accountNumber' => $sourceBankAccount,
                'configuredAccountNumber' => $purchaseOrder->budgetCampaign?->account_number,
                'isSnapshot' => filled($purchaseOrder->budget_campaign_account_number),
            ],
            'requestedBy' => $requestedBy,
            'accountType' => $accountType,
            'items' => $items,
            'official' => [
                'companyAddress' => config('procurement.company.address'),
                'companyLogo' => asset(config('procurement.company.logo')),
                'recipient' => $purchaseOrder->supplier?->name ?? 'Unavailable supplier',
                'recipientAddress' => $purchaseOrder->supplier?->address,
                'tin' => $purchaseOrder->supplier?->tax_id,
                'accountNumber' => $purchaseOrder->account_number,
                'accountName' => $purchaseOrder->account_name ?: $purchaseOrder->supplier?->name,
                'requestDate' => $purchaseRequest->created_at,
                'terms' => $purchaseOrder->payment_terms,
                'expectedDelivery' => $purchaseOrder->expected_delivery_date,
                'paymentDetails' => $purchaseOrder->payment_details,
                'remarks' => $purchaseOrder->remarks,
                'reason' => $purchaseRequest->purpose ?: $purchaseOrder->remarks,
                'netOfVat' => (float) $purchaseOrder->subtotal,
                'vat' => (float) $purchaseOrder->tax_amount,
                'total' => (float) $purchaseOrder->total_amount,
                'signatories' => $poSignatories,
            ],
            'totalAmount' => (float) $purchaseOrder->total_amount,
            'signatories' => [
                ['label' => 'Requested by', 'name' => $requestedBy, 'date' => $purchaseRequest->created_at],
                ...$poSignatories,
            ],
            'acknowledger' => $purchaseOrder->acknowledger,
            'acknowledgedAt' => $purchaseOrder->acknowledged_at,
        ];
    }
}
