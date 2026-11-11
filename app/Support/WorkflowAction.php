<?php

namespace App\Support;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** Current-stage actions shared by dashboard buttons and workflow receipts. */
class WorkflowAction
{
    /** @return array{label: string, url: string}|null */
    public static function for(User $user, PurchaseRequest $purchaseRequest): ?array
    {
        if ($purchaseRequest->status === PurchaseRequestStatus::ForBudgetProcessing) {
            return self::purchaseOrderAction($user, $purchaseRequest);
        }

        $action = match ($purchaseRequest->status) {
            PurchaseRequestStatus::Draft => ['update', 'Complete draft', null, 'purchase-requests.edit'],
            PurchaseRequestStatus::Returned => ['update', 'Revise and resubmit', null, 'purchase-requests.edit'],
            PurchaseRequestStatus::ForOmApproval => ['reviewOm', 'Review request', 'om-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForTeamScreening => ['claim', 'Claim for screening', 'screening-claim-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForItScreening,
            PurchaseRequestStatus::ForPremisesScreening => $purchaseRequest->assigned_user_id === null
                && ! ($purchaseRequest->status === PurchaseRequestStatus::ForItScreening && $user->hasRole(RoleSlug::ItStaff->value))
                ? ['claim', 'Claim for screening', 'screening-claim-actions', 'purchase-requests.show']
                : ['screen', 'Screen request', 'screening-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForProcurement,
            PurchaseRequestStatus::ForCanvassing => ['manageCanvassing', 'Add quotation', 'canvassing-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForSupplierApproval => ['reviewPresident', 'Review supplier selection', 'supplier-approval-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForBudgetMaker,
            PurchaseRequestStatus::ForBudgetChecker,
            PurchaseRequestStatus::ForBudgetProceeder,
            PurchaseRequestStatus::AwaitingPresidentFinalApproval => ['reviewBudgetFinal', 'Final budget', 'final-approval-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForPayment => ['processPayment', 'Record payment', 'payment-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForReceiving => ['receiveGoods', 'Receive goods', 'receiving-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::ForInventoryRelease => ['releaseInventory', 'Release items', 'inventory-release-actions', 'purchase-requests.show'],
            PurchaseRequestStatus::WithdrawalPending => ['acknowledgeWithdrawal', 'Acknowledge withdrawal', 'withdrawal-status', 'purchase-requests.show'],
            default => null,
        };
        if ($action === null) {
            return null;
        }
        [$ability, $label, $anchor, $route] = $action;
        if (! Gate::forUser($user)->allows($ability, $purchaseRequest)) {
            return null;
        }
        $url = route($route, $purchaseRequest);

        return ['label' => $label, 'url' => $anchor ? $url.'#'.$anchor : $url];
    }

    public static function canAct(User $user, PurchaseRequest $purchaseRequest): bool
    {
        if (self::for($user, $purchaseRequest) !== null) {
            return true;
        }
        // Recovery of a missing earlier signature must remain accessible as well.
        if ($purchaseRequest->status === PurchaseRequestStatus::ForBudgetProcessing && $purchaseRequest->purchaseOrder) {
            foreach (PurchaseOrderApprovalStage::cases() as $stage) {
                if (Gate::forUser($user)->allows('sign', [$purchaseRequest->purchaseOrder, $stage])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{label: string, url: string}|null */
    private static function purchaseOrderAction(User $user, PurchaseRequest $purchaseRequest): ?array
    {
        $gate = Gate::forUser($user);
        $order = $purchaseRequest->purchaseOrder;
        if (! $order) {
            return $gate->allows('prepare', PurchaseOrder::class)
                && PurchaseRequest::query()->eligibleForPurchaseOrderPreparation()->whereKey($purchaseRequest->id)->exists()
                ? ['label' => 'Create purchase order', 'url' => route('purchase-requests.show', $purchaseRequest).'#purchase-order-actions']
                : null;
        }
        $prepared = $order->approvalFor(PurchaseOrderApprovalStage::PreparedBy);
        if ($order->status === 'FOR_CHECKING'
            && $prepared?->status === 'APPROVED'
            && ($prepared?->hasValidSignature() ?? false)
            && ($prepared->acted_at === null || $prepared->acted_at->lt($prepared->signed_at))
            && $gate->allows('moveToChecker', $order)) {
            return ['label' => 'Submit to checker', 'url' => route('purchase-requests.show', $purchaseRequest).'#purchase-order-summary'];
        }
        $action = match ($order->status) {
            'FOR_CHECKING' => ['check', 'Check purchase order', 'purchase-order-check-actions'],
            'FOR_ACKNOWLEDGEMENT' => ['note', 'Note purchase order', 'purchase-order-acknowledgement-actions'],
            'FOR_APPROVAL' => ['approve', 'Approve purchase order', 'purchase-order-approval-actions'],
            default => null,
        };
        if ($action !== null && $gate->allows($action[0], $order)) {
            return ['label' => $action[1], 'url' => route('purchase-requests.show', $purchaseRequest).'#'.$action[2]];
        }
        if ($order->isApproved() && $order->budget_campaign_id !== null && $gate->allows('prepareBudgetRequest', $purchaseRequest)) {
            return ['label' => 'Prepare budget', 'url' => route('budget.create', ['purchase_request_id' => $purchaseRequest->id])];
        }

        return null;
    }
}
