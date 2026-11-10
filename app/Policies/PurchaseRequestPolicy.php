<?php

namespace App\Policies;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Support\RequestWithdrawal;

class PurchaseRequestPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if (in_array($ability, ['review', 'reviewOm'], true)) {
            return false;
        }
        if ($user->hasRole(RoleSlug::Admin->value) && ! in_array($ability, ['claim', 'screen', 'releaseInventory'], true)) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, PurchaseRequest $purchaseRequest): bool
    {
        if ($user->hasAnyRole([RoleSlug::ItStaff->value, RoleSlug::PremisesStaff->value])) {
            return $purchaseRequest->requestor_id === $user->id
                || PurchaseRequest::query()->visibleTo($user)->whereKey($purchaseRequest->id)->exists()
                // A paused withdrawal leaves the screening statuses; the team that must
                // acknowledge it (or did) still needs to open it.
                || RequestWithdrawal::canAcknowledge($user, $purchaseRequest)
                || ($purchaseRequest->withdrawal_acknowledged_by !== null && (int) $purchaseRequest->withdrawal_acknowledged_by === $user->id);
        }

        return $user->hasRole(RoleSlug::Admin->value)
            || $purchaseRequest->requestor_id === $user->id
            || $purchaseRequest->team_leader_id === $user->id
            || $purchaseRequest->current_responsible_role_id === $user->activeRole()?->id
            || $purchaseRequest->screening?->screened_by === $user->id
            || ($purchaseRequest->purchaseOrder && match ($purchaseRequest->purchaseOrder->status) {
                'FOR_CHECKING' => $user->hasPoRole(PurchaseOrderApprovalStage::CheckedBy),
                'FOR_ACKNOWLEDGEMENT' => $user->hasPoRole(PurchaseOrderApprovalStage::NotedBy),
                'FOR_APPROVAL' => $user->hasPoRole(PurchaseOrderApprovalStage::ApprovedBy),
                default => false,
            })
            || ($purchaseRequest->purchaseOrder && $purchaseRequest->purchaseOrder->approvals()
                ->where(fn ($approval) => $approval->where('assigned_user_id', $user->id)->orWhere('acted_by', $user->id))
                ->exists())
            || ($user->hasPoRole(PurchaseOrderApprovalStage::PreparedBy)
                && $purchaseRequest->eligibleForPurchaseOrderPreparation()->whereKey($purchaseRequest->id)->exists())
            || $user->hasAnyRole([
                RoleSlug::OmSmtsApprover->value,
                RoleSlug::Om->value,
                RoleSlug::Smt->value,
                RoleSlug::ItStaff->value,
                RoleSlug::PremisesStaff->value,
                RoleSlug::CanvassingStaff->value,
                RoleSlug::ProcurementStaff->value,
                RoleSlug::Purchasing->value,
                RoleSlug::PurchasingTeam->value,
                RoleSlug::President->value,
                RoleSlug::BudgetMaker->value,
                RoleSlug::BudgetChecker->value,
                RoleSlug::BudgetProceeder->value,
                RoleSlug::Finance->value,
                RoleSlug::FinanceBudgetStaff->value,
                RoleSlug::Inventory->value,
                RoleSlug::InventoryStaff->value,
            ]);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([
            RoleSlug::Requestor->value,
            RoleSlug::TeamLeader->value,
            RoleSlug::Admin->value,
        ]);
    }

    public function update(User $user, PurchaseRequest $purchaseRequest): bool
    {
        $editable = in_array($purchaseRequest->status, [
            PurchaseRequestStatus::Draft,
            PurchaseRequestStatus::Returned,
        ], true);

        $ownerCanEdit = $editable && $purchaseRequest->requestor_id === $user->id;

        return ($editable && $user->hasRole(RoleSlug::Admin->value))
            || $ownerCanEdit;
    }

    public function submit(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return in_array($purchaseRequest->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::Returned], true)
            && ($user->hasRole(RoleSlug::Admin->value) || $purchaseRequest->requestor_id === $user->id);
    }

    public function review(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->reviewOm($user, $purchaseRequest);
    }

    public function reviewOm(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return false;
    }

    public function reviewPresident(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->hasRole(RoleSlug::President->value)
            && $purchaseRequest->status === PurchaseRequestStatus::ForSupplierApproval;
    }

    public function reviewBudgetFinal(User $user, PurchaseRequest $purchaseRequest): bool
    {
        // Purchasing Team finalizes budgets from the Budget Maker workflow tab.
        return $user->hasAnyRole([RoleSlug::Purchasing->value, RoleSlug::PurchasingTeam->value, RoleSlug::ProcurementStaff->value])
            && in_array($purchaseRequest->status, [
                PurchaseRequestStatus::ForBudgetMaker,
                PurchaseRequestStatus::ForBudgetChecker,
                PurchaseRequestStatus::ForBudgetProceeder,
                PurchaseRequestStatus::AwaitingPresidentFinalApproval,
            ], true);
    }

    public function manageCanvassing(User $user, PurchaseRequest $purchaseRequest): bool
    {
        if ($user->hasRole(RoleSlug::PurchasingTeam->value)) {
            return in_array($purchaseRequest->status, [
                PurchaseRequestStatus::ForProcurement,
                PurchaseRequestStatus::ForCanvassing,
            ], true);
        }

        if ($user->hasAnyRole([RoleSlug::CanvassingStaff->value, RoleSlug::Purchasing->value, RoleSlug::ProcurementStaff->value, RoleSlug::Admin->value])) {
            return $purchaseRequest->status !== PurchaseRequestStatus::ForProcurement;
        }

        return $this->recommendScreeningQuotation($user, $purchaseRequest);
    }

    public function viewPriority(User $user, PurchaseRequest $purchaseRequest): bool
    {
        // The requestor chooses the priority when submitting, so they may see it on their own request.
        return $user->canViewPriority() || $purchaseRequest->requestor_id === $user->id;
    }

    /** IT / Premises set the priority while the request is in their hands: screening, then quotation entry. */
    public function setScreeningPriority(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->screen($user, $purchaseRequest)
            || $this->recommendScreeningQuotation($user, $purchaseRequest);
    }

    public function recommendScreeningQuotation(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $purchaseRequest->status === PurchaseRequestStatus::ForProcurement
            && $purchaseRequest->assigned_user_id === $user->id
            && $purchaseRequest->screening?->decision === 'UNAVAILABLE'
            && (($purchaseRequest->request_type === 'IT' && $user->hasRole(RoleSlug::ItStaff->value))
                || ($purchaseRequest->request_type === 'PREMISES' && $user->hasRole(RoleSlug::PremisesStaff->value)));
    }

    public function undoScreening(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->recommendScreeningQuotation($user, $purchaseRequest)
            && ! $purchaseRequest->quotations()->exists();
    }

    public function prepareBudgetRequest(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->hasAnyRole([
            RoleSlug::Admin->value,
            RoleSlug::Purchasing->value,
            RoleSlug::BudgetMaker->value,
            RoleSlug::PurchasingTeam->value,
        ]) && $purchaseRequest->status === PurchaseRequestStatus::ForBudgetProcessing
            && ($purchaseRequest->purchaseOrder?->isApproved() ?? $purchaseRequest->skippedPurchaseOrder())
            && ! $purchaseRequest->budgetRequest()->exists();
    }

    public function manageBudget(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->hasAnyRole([
            RoleSlug::Admin->value,
            RoleSlug::Purchasing->value,
        ]);
    }

    public function processPayment(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->hasAnyRole([
            RoleSlug::Finance->value,
            RoleSlug::Admin->value,
            RoleSlug::Purchasing->value,
        ]);
    }

    public function receiveGoods(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->hasAnyRole([
            RoleSlug::Inventory->value,
            RoleSlug::InventoryStaff->value,
            // Finance records receipt of goods it paid for (it can add stock, never release it).
            RoleSlug::Finance->value,
            RoleSlug::Admin->value,
        ]);
    }

    public function screen(User $user, PurchaseRequest $purchaseRequest): bool
    {
        if (! $this->belongsToUsersScreeningTeam($user, $purchaseRequest)) {
            return false;
        }

        return $purchaseRequest->assigned_user_id === $user->id
            || ($purchaseRequest->assigned_user_id === null
                && $purchaseRequest->status === PurchaseRequestStatus::ForItScreening
                && $user->hasRole(RoleSlug::ItStaff->value));
    }

    public function releaseInventory(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $purchaseRequest->status === PurchaseRequestStatus::ForInventoryRelease
            && $user->hasAnyRole([RoleSlug::Inventory->value, RoleSlug::InventoryStaff->value, RoleSlug::Admin->value]);
    }

    public function claim(User $user, PurchaseRequest $purchaseRequest): bool
    {
        $teamStatus = match ($user->activeRole()?->slug) {
            RoleSlug::ItStaff->value => PurchaseRequestStatus::ForItScreening,
            RoleSlug::PremisesStaff->value => PurchaseRequestStatus::ForPremisesScreening,
            default => null,
        };

        $team = $teamStatus === PurchaseRequestStatus::ForItScreening ? 'IT' : 'PREMISES';

        return $purchaseRequest->assigned_user_id === null
            && in_array($purchaseRequest->status, [
                PurchaseRequestStatus::ForTeamScreening,
                $teamStatus,
            ], true)
            && $teamStatus !== null
            // IT-specific requests go directly to IT screening; no separate claim is needed.
            && ! ($teamStatus === PurchaseRequestStatus::ForItScreening
                && $purchaseRequest->status === PurchaseRequestStatus::ForItScreening)
            // A request sent to the other team is not claimable; requests without a team are.
            && in_array($purchaseRequest->requested_team, [null, $team], true);
    }

    /** The requestor may void a draft, or withdraw a submitted request until a Purchase Order exists. */
    public function withdraw(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->id === $purchaseRequest->requestor_id
            && in_array($purchaseRequest->status, [PurchaseRequestStatus::Draft, ...PurchaseRequestStatus::withdrawable()], true)
            // Lists load the PO already; use it instead of one query per card.
            && ($purchaseRequest->relationLoaded('purchaseOrder')
                ? $purchaseRequest->purchaseOrder === null
                : ! $purchaseRequest->purchaseOrder()->exists());
    }

    public function acknowledgeWithdrawal(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return RequestWithdrawal::canAcknowledge($user, $purchaseRequest);
    }

    public function unclaim(User $user, PurchaseRequest $purchaseRequest): bool
    {
        $teamStatus = match ($user->activeRole()?->slug) {
            RoleSlug::ItStaff->value => PurchaseRequestStatus::ForItScreening,
            RoleSlug::PremisesStaff->value => PurchaseRequestStatus::ForPremisesScreening,
            default => null,
        };

        return $teamStatus !== null
            && $purchaseRequest->assigned_user_id === $user->id
            && $purchaseRequest->status === $teamStatus
            && ! $purchaseRequest->screening()->exists()
            && ! $purchaseRequest->quotations()->exists();
    }

    public function delete(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return false;
    }

    private function belongsToUsersScreeningTeam(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return ($purchaseRequest->request_type === 'IT'
                && $purchaseRequest->status === PurchaseRequestStatus::ForItScreening
                && $user->hasRole(RoleSlug::ItStaff->value))
            || ($purchaseRequest->request_type === 'PREMISES'
                && $purchaseRequest->status === PurchaseRequestStatus::ForPremisesScreening
                && $user->hasRole(RoleSlug::PremisesStaff->value));
    }
}
