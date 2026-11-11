<?php

namespace App\Support;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;

/**
 * Once a user signs and hands a request to the next stage, their view of the
 * request closes to a receipt saying where it went — the same pattern as the
 * Purchase Order stages. The receipt only shows while the request is still
 * waiting at that next stage; once the next person acts, the full page returns.
 */
class RequestStageReceipt
{
    /** @return array{title: string, message: string, action: string, log: \App\Models\AuditLog}|null */
    public static function for(PurchaseRequest $purchaseRequest, User $user): ?array
    {
        if ($user->hasRole(RoleSlug::Admin->value)) {
            return null;
        }

        $stages = self::stages();
        $handoff = $purchaseRequest->auditLogs
            ->filter(fn ($log) => array_key_exists($log->action, $stages))
            ->sortByDesc('id')
            ->first();

        if (! $handoff || (int) $handoff->user_id !== (int) $user->id) {
            return null;
        }

        // An unavailable screening decision opens the quotation work step for
        // the same IT / Premises user, so it must show the request form rather
        // than close it with a hand-off receipt.
        if ($handoff->action === 'INVENTORY_UNAVAILABLE') {
            return null;
        }

        $stage = $stages[$handoff->action];
        if (! in_array($purchaseRequest->status, $stage['statuses'], true) || self::hasPendingWork($purchaseRequest, $user)) {
            return null;
        }

        $supplierName = $handoff->metadata['selected_supplier'] ?? (isset($handoff->metadata['supplier_id'])
            ? Supplier::query()->whereKey($handoff->metadata['supplier_id'])->value('name')
            : null);

        return [
            'title' => $stage['title'],
            'message' => strtr($stage['message'], [
                ':date' => $handoff->created_at->format('M d, Y g:i A'),
                ':supplier' => $supplierName ?? 'a supplier',
            ]),
            'action' => $handoff->action,
            'log' => $handoff,
        ];
    }

    /**
     * Short "what I did" labels for each hand-off, used by the dashboard's processed list.
     *
     * @return array<string, string>
     */
    public static function handoffLabels(): array
    {
        return [
            'SUBMITTED_FOR_SCREENING' => 'Submitted to screening',
            'RESUBMITTED_FOR_SCREENING' => 'Resubmitted to screening',
            'SUBMITTED_FOR_OM_APPROVAL' => 'Submitted to OM',
            'RESUBMITTED_FOR_OM_APPROVAL' => 'Resubmitted to OM',
            'OM_APPROVED_FOR_SCREENING' => 'Approved',
            'OM_RETURNED' => 'Returned to requestor',
            'INVENTORY_AVAILABLE' => 'Inventory available',
            'INVENTORY_RELEASED' => 'Inventory released',
            'INVENTORY_UNAVAILABLE' => 'Awaiting quotations',
            'SCREENING_QUOTATIONS_RECORDED' => 'Submitted to canvassing',
            'SCREENING_QUOTATION_RECOMMENDED' => 'Recommended to Purchasing',
            'CANVASSING_UPDATED' => 'Submitted to President',
            'DIRECT_PURCHASE_ORDER_PREPARATION' => 'Purchase order prepared',
            'PRESIDENT_SUPPLIER_APPROVAL_APPROVED' => 'Supplier approved',
            'PRESIDENT_SUPPLIER_APPROVAL_REJECTED' => 'Returned to canvassing',
        ];
    }

    /** The same user may also hold the next step (e.g. an OM who is also a PO approver). */
    private static function hasPendingWork(PurchaseRequest $purchaseRequest, User $user): bool
    {
        return WorkflowAction::canAct($user, $purchaseRequest);
    }

    /** @return array<string, array{statuses: list<PurchaseRequestStatus>, title: string, message: string}> */
    private static function stages(): array
    {
        $submitted = [
            'statuses' => [PurchaseRequestStatus::ForTeamScreening, PurchaseRequestStatus::ForItScreening, PurchaseRequestStatus::ForPremisesScreening],
            'title' => 'Submitted to IT / Premises screening.',
            'message' => 'You signed and submitted this request on :date. It has moved directly to IT / Premises for screening. Your assigned OM and SMT receive email updates only. No further action is required from you unless the request is returned.',
        ];

        return [
            'SUBMITTED_FOR_SCREENING' => $submitted,
            'RESUBMITTED_FOR_SCREENING' => $submitted,
            'SUBMITTED_FOR_OM_APPROVAL' => $submitted,
            'RESUBMITTED_FOR_OM_APPROVAL' => $submitted,
            'OM_APPROVED_FOR_SCREENING' => [
                'statuses' => [PurchaseRequestStatus::ForTeamScreening],
                'title' => 'Submitted to IT / Premises.',
                'message' => 'You approved this request on :date. It will now move to IT / Premises for classification and inventory screening. Your review stage is closed and no further action is required from you.',
            ],
            'OM_APPROVED_FOR_IT_SCREENING' => [
                'statuses' => [PurchaseRequestStatus::ForItScreening],
                'title' => 'Submitted to IT screening.',
                'message' => 'You approved this request on :date. It moved straight to IT for inventory screening. Your review stage is closed and no further action is required from you.',
            ],
            'OM_APPROVED_FOR_PREMISES_SCREENING' => [
                'statuses' => [PurchaseRequestStatus::ForPremisesScreening],
                'title' => 'Submitted to Premises screening.',
                'message' => 'You approved this request on :date. It moved straight to Premises for inventory screening. Your review stage is closed and no further action is required from you.',
            ],
            'OM_RETURNED' => [
                'statuses' => [PurchaseRequestStatus::Returned],
                'title' => 'Returned to Requestor.',
                'message' => 'You returned this request on :date. It will now move back to the requestor for correction. Your review stage is closed until the request is resubmitted.',
            ],
            'INVENTORY_AVAILABLE' => [
                'statuses' => [PurchaseRequestStatus::ForInventoryRelease, PurchaseRequestStatus::Fulfilled],
                'title' => 'Inventory released.',
                'message' => 'You signed the available inventory decision on :date. Stock was released automatically and the request is fulfilled.',
            ],
            'INVENTORY_UNAVAILABLE' => [
                'statuses' => [PurchaseRequestStatus::ForProcurement],
                'title' => 'Awaiting Quotations.',
                'message' => 'You marked inventory unavailable on :date. The request will now move to the quotation step before Canvassing. Your screening stage is closed and no further action is required from you.',
            ],
            'SCREENING_QUOTATIONS_RECORDED' => [
                'statuses' => [PurchaseRequestStatus::ForCanvassing],
                'title' => 'Submitted to Canvassing.',
                'message' => 'You completed quotations on :date. The request has moved to Canvassing for supplier comparison and recommendation. Your quotation stage is closed and no further action is required from you.',
            ],
            'SCREENING_QUOTATION_RECOMMENDED' => [
                'statuses' => [PurchaseRequestStatus::ForProcurement, PurchaseRequestStatus::ForCanvassing],
                'title' => 'Submitted to Purchasing.',
                'message' => 'You recommended :supplier on :date. This request will move forward to the Purchasing team for review. Your quotation stage is closed and no further action is required from you unless the request is returned.',
            ],
            'CANVASSING_UPDATED' => [
                'statuses' => [PurchaseRequestStatus::ForSupplierApproval],
                'title' => 'Submitted to President.',
                'message' => 'You submitted the supplier recommendation on :date. The request will now move to the President for supplier approval. Your canvassing stage is closed and no further action is required from you unless the request is returned.',
            ],
            'CANVASSING_SUBMITTED_WITHOUT_QUOTATION' => [
                'statuses' => [PurchaseRequestStatus::ForSupplierApproval],
                'title' => 'Submitted to President.',
                'message' => 'You submitted this request without supplier pricing on :date. The President can now review and acknowledge it.',
            ],
            'CANVASSING_SUBMITTED_WITHOUT_ADDITIONAL_QUOTATION' => [
                'statuses' => [PurchaseRequestStatus::ForSupplierApproval],
                'title' => 'Submitted to President.',
                'message' => 'You submitted this request to the President on :date. Previously saved quotations were kept for review.',
            ],
            'SCREENING_SUBMITTED_WITHOUT_QUOTATION' => [
                'statuses' => [PurchaseRequestStatus::ForCanvassing],
                'title' => 'Submitted to Canvassing.',
                'message' => 'You handed this request to Canvassing on :date without adding a quotation.',
            ],
            'PRESIDENT_NO_QUOTATION_ACKNOWLEDGED' => [
                'statuses' => [PurchaseRequestStatus::ForCanvassing],
                'title' => 'Acknowledged and returned to Purchasing.',
                'message' => 'You acknowledged this request without supplier pricing on :date. Purchasing can now follow up.',
            ],
            'PRESIDENT_SUPPLIER_APPROVAL_APPROVED' => [
                'statuses' => [PurchaseRequestStatus::ForBudgetProcessing],
                'title' => 'Submitted to Purchasing.',
                'message' => 'You approved :supplier on :date. The request will now move to Purchasing for Purchase Order preparation. Your approval stage is closed and no further action is required from you.',
            ],
            'PRESIDENT_SUPPLIER_APPROVAL_REJECTED' => [
                'statuses' => [PurchaseRequestStatus::ForCanvassing],
                'title' => 'Returned to Purchasing.',
                'message' => 'You returned this request to canvassing on :date. Purchasing will now revise the quotations. Your approval stage is closed until a new recommendation is submitted.',
            ],
        ];
    }
}
