<?php

namespace App\Policies;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\RoleSlug;
use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    public const CHECKER_WORKFLOW_VERSION = 'self-check-v1';

    public function prepare(User $user): bool
    {
        return $user->hasPoRole(PurchaseOrderApprovalStage::PreparedBy);
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        $activeStage = match ($purchaseOrder->status) {
            'FOR_CHECKING' => PurchaseOrderApprovalStage::CheckedBy,
            'FOR_ACKNOWLEDGEMENT' => PurchaseOrderApprovalStage::NotedBy,
            'FOR_APPROVAL' => PurchaseOrderApprovalStage::ApprovedBy,
            default => null,
        };
        if ($activeStage && $user->hasPoRole($activeStage)) {
            return true;
        }
        // The Purchasing Team prepares every PO as a team, so it may view any of them (read-only).
        if ($user->hasRole(RoleSlug::PurchasingTeam->value)) {
            return true;
        }

        $hasWorkflowRows = $purchaseOrder->approvals()->exists();
        if ($hasWorkflowRows) {
            return $purchaseOrder->approvals()
                ->where(fn ($approval) => $approval->where('assigned_user_id', $user->id)->orWhere('acted_by', $user->id))
                ->exists();
        }

        // Pre-separation POs have no PO approval rows. Their documents remain available only
        // to users already authorized to view the linked Purchase Request; no PO assignment is inferred.
        return $purchaseOrder->purchaseRequest !== null
            && $user->can('view', $purchaseOrder->purchaseRequest);
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        if (! $this->isAssignedActor($user, $purchaseOrder, PurchaseOrderApprovalStage::PreparedBy)) {
            return false;
        }

        // Once the preparer has explicitly handed the PO to the checker, their
        // preparation form is closed. A later rollback reopens it through the
        // existing workflow by making this stage actionable again.
        return ! $this->stageCompleted($purchaseOrder, PurchaseOrderApprovalStage::PreparedBy);
    }

    public function moveToChecker(User $user, PurchaseOrder $purchaseOrder): bool
    {
        $prepared = $purchaseOrder->approvalFor(PurchaseOrderApprovalStage::PreparedBy);
        $checked = $purchaseOrder->approvalFor(PurchaseOrderApprovalStage::CheckedBy);

        return $purchaseOrder->status === 'FOR_CHECKING'
            && $this->isAssignedActor($user, $purchaseOrder, PurchaseOrderApprovalStage::PreparedBy)
            && $prepared?->status === 'APPROVED'
            && ($prepared?->hasValidSignature() ?? false)
            && ($prepared?->acted_at === null || $prepared->acted_at->lt($prepared->signed_at));
    }

    public function check(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->status === 'FOR_CHECKING'
            && $this->stageCompleted($purchaseOrder, PurchaseOrderApprovalStage::PreparedBy)
            && $this->isAssignedActor($user, $purchaseOrder, PurchaseOrderApprovalStage::CheckedBy);
    }

    public function note(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->status === 'FOR_ACKNOWLEDGEMENT'
            && $this->stageActionRecorded($purchaseOrder, PurchaseOrderApprovalStage::CheckedBy)
            && $this->isAssignedActor($user, $purchaseOrder, PurchaseOrderApprovalStage::NotedBy);
    }

    public function approve(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->status === 'FOR_APPROVAL'
            && $this->stageActionRecorded($purchaseOrder, PurchaseOrderApprovalStage::NotedBy)
            && $this->isAssignedActor($user, $purchaseOrder, PurchaseOrderApprovalStage::ApprovedBy);
    }

    /**
     * A signer may revise their own completed task (Prepared By: PO details;
     * other stages: their remarks) until a Budget Request uses the PO.
     */
    public function editTask(User $user, PurchaseOrder $purchaseOrder, PurchaseOrderApprovalStage $stage): bool
    {
        $approval = $purchaseOrder->approvalFor($stage);

        return $approval !== null
            && $this->isAssignedActor($user, $purchaseOrder, $stage)
            && in_array($approval->status, ['APPROVED', 'REJECTED'], true)
            && $approval->acted_at !== null
            && ! $purchaseOrder->isLockedForTaskEdits();
    }

    public function sign(User $user, PurchaseOrder $purchaseOrder, PurchaseOrderApprovalStage $stage): bool
    {
        $approval = $purchaseOrder->approvalFor($stage);

        if (! $approval || $approval->hasValidSignature() || ! $this->isAssignedActor($user, $purchaseOrder, $stage)) {
            return false;
        }

        if (! $this->previousStagesCompleted($purchaseOrder, $stage)) {
            return false;
        }

        return $this->earliestMissingSignatureStage($purchaseOrder) === $stage;
    }

    private function earliestMissingSignatureStage(PurchaseOrder $purchaseOrder): ?PurchaseOrderApprovalStage
    {
        $lastRequiredStage = match ($purchaseOrder->status) {
            'FOR_CHECKING' => PurchaseOrderApprovalStage::CheckedBy,
            'FOR_ACKNOWLEDGEMENT' => PurchaseOrderApprovalStage::NotedBy,
            'FOR_APPROVAL' => PurchaseOrderApprovalStage::ApprovedBy,
            default => null,
        };

        if ($lastRequiredStage === null) {
            return null;
        }

        foreach (PurchaseOrderApprovalStage::cases() as $candidate) {
            if (! $this->stageSigned($purchaseOrder, $candidate)) {
                return $candidate;
            }

            if ($candidate === $lastRequiredStage) {
                break;
            }
        }

        return null;
    }

    private function isAssignedActor(User $user, PurchaseOrder $purchaseOrder, PurchaseOrderApprovalStage $stage): bool
    {
        if ($stage !== PurchaseOrderApprovalStage::PreparedBy) {
            $approval = $purchaseOrder->approvalFor($stage);

            return $user->hasPoRole($stage) && $approval !== null
                && (! $approval->hasValidSignature() || (int) $approval->acted_by === (int) $user->id);
        }

        return $user->hasPoRole($stage)
            && (int) $purchaseOrder->approvalFor($stage)?->assigned_user_id === (int) $user->id;
    }

    private function stageActionRecorded(PurchaseOrder $purchaseOrder, PurchaseOrderApprovalStage $stage): bool
    {
        return $purchaseOrder->approvalFor($stage)?->status === 'APPROVED';
    }

    private function previousStagesCompleted(PurchaseOrder $purchaseOrder, PurchaseOrderApprovalStage $stage): bool
    {
        foreach (PurchaseOrderApprovalStage::cases() as $candidate) {
            if ($candidate === $stage) {
                break;
            }

            if (! $this->stageCompleted($purchaseOrder, $candidate)) {
                return false;
            }
        }

        return true;
    }

    private function stageCompleted(PurchaseOrder $purchaseOrder, PurchaseOrderApprovalStage $stage): bool
    {
        $approval = $purchaseOrder->approvalFor($stage);

        return $approval?->status === 'APPROVED'
            && $approval->hasValidSignature()
            && $approval->acted_at !== null
            && $approval->signed_at !== null
            && $approval->acted_at->greaterThanOrEqualTo($approval->signed_at);
    }

    private function stageSigned(PurchaseOrder $purchaseOrder, PurchaseOrderApprovalStage $stage): bool
    {
        return $purchaseOrder->approvalFor($stage)?->hasValidSignature() ?? false;
    }
}
