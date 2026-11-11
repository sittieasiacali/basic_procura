<?php

namespace App\Support;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\BudgetRequest;
use App\Models\PurchaseRequest;
use App\Models\User;

/**
 * Who acknowledges a requestor's withdrawal: the staff handling the request at the
 * stage where processing stopped (plus the claimed screener, and Admin).
 */
class RequestWithdrawal
{
    /** @return list<string> role slugs that handle the stopped stage */
    public static function acknowledgerRoles(PurchaseRequest $purchaseRequest): array
    {
        $stage = PurchaseRequestStatus::tryFrom((string) $purchaseRequest->withdrawal_stage);
        $team = $purchaseRequest->request_type === 'PREMISES' || $purchaseRequest->requested_team === 'PREMISES'
            ? 'PREMISES'
            : ($purchaseRequest->request_type === 'IT' || $purchaseRequest->requested_team === 'IT' ? 'IT' : null);
        $screeners = match ($team) {
            'IT' => [RoleSlug::ItStaff->value],
            'PREMISES' => [RoleSlug::PremisesStaff->value],
            default => [RoleSlug::ItStaff->value, RoleSlug::PremisesStaff->value],
        };
        $purchasing = [RoleSlug::Purchasing->value, RoleSlug::PurchasingTeam->value, RoleSlug::ProcurementStaff->value];

        return match ($stage) {
            PurchaseRequestStatus::ForTlApproval => [RoleSlug::TeamLeader->value, RoleSlug::Tl->value],
            PurchaseRequestStatus::ForOmApproval,
            PurchaseRequestStatus::Returned,
            PurchaseRequestStatus::ForOmSmtsApproval => [RoleSlug::OmSmtsApprover->value, RoleSlug::Om->value],
            PurchaseRequestStatus::ForTeamScreening,
            PurchaseRequestStatus::ForItScreening,
            PurchaseRequestStatus::ForPremisesScreening,
            PurchaseRequestStatus::ForProcurement => $screeners,
            PurchaseRequestStatus::ForInventoryRelease => [RoleSlug::Inventory->value, RoleSlug::InventoryStaff->value, ...$screeners],
            PurchaseRequestStatus::ForCanvassing => [RoleSlug::CanvassingStaff->value, ...$purchasing],
            PurchaseRequestStatus::ForSupplierApproval => [RoleSlug::President->value],
            PurchaseRequestStatus::SupplierApproved,
            PurchaseRequestStatus::Approved,
            PurchaseRequestStatus::PoCreated,
            PurchaseRequestStatus::ForBudgetProcessing,
            PurchaseRequestStatus::ForBudgetMaker,
            PurchaseRequestStatus::ForBudgetChecker,
            PurchaseRequestStatus::ForBudgetProceeder,
            PurchaseRequestStatus::AwaitingPresidentFinalApproval => $purchasing,
            default => [],
        };
    }

    /** A request still in the requestor's hands (never submitted, or returned to them) is voided at once. */
    public static function voidsImmediately(PurchaseRequest $purchaseRequest): bool
    {
        return in_array($purchaseRequest->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::Returned], true);
    }

    /** Why the requestor can no longer void this request, or null when they can. */
    public static function blockedReason(PurchaseRequest $purchaseRequest): ?string
    {
        return match (true) {
            $purchaseRequest->status === PurchaseRequestStatus::WithdrawalPending => 'A void was already requested for this request. It is waiting for the handling staff to acknowledge it.',
            $purchaseRequest->status === PurchaseRequestStatus::Voided => 'This request is already voided.',
            self::budgetFinalized($purchaseRequest),
            ! in_array($purchaseRequest->status, [PurchaseRequestStatus::Draft, ...PurchaseRequestStatus::withdrawable()], true) => 'This request can no longer be voided because the budget has been finalized or processing is complete.',
            default => null,
        };
    }

    public static function budgetFinalized(PurchaseRequest $purchaseRequest): bool
    {
        // Include grouped budgets through the allocation table, even after a status rollback.
        return BudgetRequest::query()
            ->where(fn ($query) => $query->where('purchase_request_id', $purchaseRequest->id)
                ->orWhereHas('allocations', fn ($allocation) => $allocation->where('purchase_request_id', $purchaseRequest->id)))
            ->where(fn ($query) => $query->whereNotNull('final_approval_at')
                ->orWhere('final_approval_status', 'APPROVED')
                ->orWhereIn('status', ['APPROVED', 'PARTIALLY_PAID', 'PAID']))
            ->exists();
    }

    public static function canAcknowledge(User $user, PurchaseRequest $purchaseRequest): bool
    {
        if ($purchaseRequest->status !== PurchaseRequestStatus::WithdrawalPending || $user->id === $purchaseRequest->requestor_id) {
            return false;
        }

        return $user->hasRole(RoleSlug::Admin->value)
            || ($purchaseRequest->assigned_user_id !== null && $purchaseRequest->assigned_user_id === $user->id)
            || $user->hasAnyRole(self::acknowledgerRoles($purchaseRequest));
    }

    /** For a team's voided monitoring: withdrawn while at a stage this user's team handles, or acknowledged by them. */
    public static function concerns(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $purchaseRequest->withdrawal_acknowledged_by === $user->id
            || $user->hasAnyRole(self::acknowledgerRoles($purchaseRequest));
    }

    /** Readable name of the stage where processing stopped. */
    public static function stageLabel(?string $stage): string
    {
        return match (PurchaseRequestStatus::tryFrom((string) $stage)) {
            PurchaseRequestStatus::Draft => 'Draft (not submitted)',
            PurchaseRequestStatus::ForTlApproval => 'Team Leader approval',
            PurchaseRequestStatus::ForOmApproval => 'Operations Manager approval',
            PurchaseRequestStatus::Returned => 'Returned to requestor',
            PurchaseRequestStatus::ForOmSmtsApproval => 'SMT approval',
            PurchaseRequestStatus::ForTeamScreening => 'IT / Premises classification',
            PurchaseRequestStatus::ForItScreening => 'IT screening',
            PurchaseRequestStatus::ForPremisesScreening => 'Premises screening',
            PurchaseRequestStatus::ForInventoryRelease => 'Inventory release',
            PurchaseRequestStatus::ForProcurement => 'Quotation',
            PurchaseRequestStatus::ForCanvassing => 'Canvassing',
            PurchaseRequestStatus::ForSupplierApproval => 'President supplier approval',
            PurchaseRequestStatus::SupplierApproved, PurchaseRequestStatus::Approved, PurchaseRequestStatus::PoCreated, PurchaseRequestStatus::ForBudgetProcessing => 'Purchase Order preparation',
            PurchaseRequestStatus::ForBudgetMaker, PurchaseRequestStatus::ForBudgetChecker, PurchaseRequestStatus::ForBudgetProceeder, PurchaseRequestStatus::AwaitingPresidentFinalApproval => 'Budget Request finalization',
            default => $stage ? str($stage)->replace('_', ' ')->title()->toString() : 'Not recorded',
        };
    }
}
