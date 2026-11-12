<?php

namespace App\Services;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\ScreeningRouter;
use Illuminate\Validation\ValidationException;

class PurchaseRequestWorkflow
{
    public function __construct(private WorkflowEmailNotifier $activityNotifier) {}

    public function submitForScreening(PurchaseRequest $purchaseRequest, User $user, ?string $remarks, string $signatureName, string $signatureData): void
    {
        $campaign = $purchaseRequest->requestor?->campaign;
        if (! $campaign || ! $campaign->is_active) {
            throw ValidationException::withMessages(['campaign' => 'Ask the administrator to assign an active campaign before submitting.']);
        }
        $action = $purchaseRequest->status === PurchaseRequestStatus::Returned
            ? 'RESUBMITTED_FOR_OM_APPROVAL' : 'SUBMITTED_FOR_OM_APPROVAL';
        $team = $purchaseRequest->requested_team;
        $metadata = [];
        if ($team === null && ($matches = app(ScreeningRouter::class)->itMatches($purchaseRequest)) !== []) {
            $team = 'IT';
            $metadata = ['auto_routed' => 'IT', 'matched_keywords' => $matches];
        } elseif ($team !== null) {
            $metadata = ['routed_by' => 'requestor', 'requested_team' => $team, 'request_category' => $purchaseRequest->request_category];
        }
        $status = match ($team) {
            'IT' => PurchaseRequestStatus::ForItScreening,
            'PREMISES' => PurchaseRequestStatus::ForPremisesScreening,
            default => PurchaseRequestStatus::ForTeamScreening,
        };
        $purchaseRequest->forceFill(['request_type' => $team ?? 'UNCLASSIFIED', 'assigned_user_id' => null]);
        $purchaseRequest->forceFill(['chat_campaign_id' => $purchaseRequest->chat_campaign_id ?? $campaign->id]);
        $this->transition($purchaseRequest, PurchaseRequestStatus::ForOmApproval, $user, $action, filled($remarks) ? $remarks : 'Submitted for OM approval. SMT acknowledgement is optional.', 'REQUESTOR_SUBMISSION', $metadata, $signatureName, $signatureData);
    }

    public function recordCreated(PurchaseRequest $purchaseRequest, User $user): void
    {
        $this->recordAudit(
            $purchaseRequest,
            $user,
            'CREATED_DRAFT',
            null,
            PurchaseRequestStatus::Draft,
            $purchaseRequest->remarks,
        );
    }

    public function recordUpdated(PurchaseRequest $purchaseRequest, User $user): void
    {
        $this->recordAudit(
            $purchaseRequest,
            $user,
            'UPDATED',
            $purchaseRequest->status,
            $purchaseRequest->status,
            'Purchase request details updated.',
        );
    }

    public function recordActivity(
        PurchaseRequest $purchaseRequest,
        User $user,
        string $action,
        ?string $remarks = null,
        array $metadata = [],
    ): void {
        $this->recordAudit(
            $purchaseRequest,
            $user,
            $action,
            $purchaseRequest->status,
            $purchaseRequest->status,
            $remarks,
            $metadata,
        );
        if (filled($remarks)) {
            app(ProcuraChat::class)->activity($purchaseRequest, $user, $action, $remarks);
        }
    }

    public function transition(
        PurchaseRequest $purchaseRequest,
        PurchaseRequestStatus $newStatus,
        User $user,
        string $action,
        ?string $remarks = null,
        ?string $approvalLevel = null,
        array $metadata = [],
        ?string $signatureName = null,
        ?string $signatureData = null,
    ): void {
        if (($newStatus === PurchaseRequestStatus::Returned || $action === 'PRESIDENT_SUPPLIER_APPROVAL_REJECTED') && blank($remarks)) {
            throw ValidationException::withMessages(['remarks' => 'Explain the correction needed before returning this request.']);
        }

        $previousStatus = $purchaseRequest->status;

        if (! $previousStatus->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => "The request cannot move from {$previousStatus->value} to {$newStatus->value}.",
            ]);
        }

        $purchaseRequest->forceFill([
            'status' => $newStatus,
            'current_responsible_role_id' => $this->responsibleRoleId($newStatus, $purchaseRequest),
            'submitted_at' => in_array($action, ['SUBMITTED_FOR_SCREENING', 'RESUBMITTED_FOR_SCREENING', 'SUBMITTED_FOR_OM_APPROVAL', 'RESUBMITTED_FOR_OM_APPROVAL'], true) ? now() : $purchaseRequest->submitted_at,
        ])->save();

        if ($approvalLevel !== null) {
            $purchaseRequest->approvals()->create([
                'user_id' => $user->id,
                'approval_level' => $approvalLevel,
                'action' => $action,
                'previous_status' => $previousStatus->value,
                'new_status' => $newStatus->value,
                'remarks' => $remarks,
                'signature_name' => $signatureName,
                'signature_data' => $signatureData,
                'acted_at' => now(),
            ]);
        }

        $this->recordAudit($purchaseRequest, $user, $action, $previousStatus, $newStatus, $remarks, $metadata);
        if ($previousStatus !== $newStatus) {
            $message = match (true) {
                $action === 'PURCHASING_BUDGET_APPROVED' => 'Your budget has been finalized. Void requests are no longer allowed. The request is ready for payment.',
                $newStatus === PurchaseRequestStatus::Returned || $action === 'PRESIDENT_SUPPLIER_APPROVAL_REJECTED' => 'Returned for correction: '.$remarks,
                default => 'Current stage: '.str($newStatus->value)->replace('_', ' ')->title(),
            };
            app(ProcurementNotifications::class)->send($purchaseRequest, $action, $message);
        }
        if ($previousStatus !== $newStatus) {
            app(ProcuraChat::class)->workflow($purchaseRequest, $user, $action, $remarks);
        }
        $this->activityNotifier->requestMoved($purchaseRequest, $previousStatus, $action);
        if (request()->hasSession()) {
            request()->session()->flash('workflow_request_code', $purchaseRequest->request_number);
            request()->session()->flash('workflow_next_stage', match ($newStatus) {
                PurchaseRequestStatus::ForCanvassing => 'Sent to Canvassing',
                PurchaseRequestStatus::ForSupplierApproval => 'Sent to President review',
                PurchaseRequestStatus::ForProcurement => 'Screening completed · Quotation / Canvassing preparation',
                default => 'Current stage: '.str($newStatus->value)->replace('_', ' ')->title(),
            });
        }
    }

    private function responsibleRoleId(PurchaseRequestStatus $status, PurchaseRequest $purchaseRequest): ?int
    {
        if (in_array($status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::Returned], true)) {
            $requestor = $purchaseRequest->requestor;

            return $requestor?->assignedRoles()->first(fn ($role) => in_array($role->slug, [RoleSlug::Requestor->value, RoleSlug::TeamLeader->value, RoleSlug::Tl->value], true))?->id
                ?? $requestor?->role_id;
        }

        // A paused withdrawal stays with the staff handling the stage where it stopped.
        if ($status === PurchaseRequestStatus::WithdrawalPending) {
            return $purchaseRequest->current_responsible_role_id;
        }

        $slug = match ($status) {
            PurchaseRequestStatus::ForOmApproval => RoleSlug::Om->value,
            PurchaseRequestStatus::ForItScreening => RoleSlug::ItStaff->value,
            PurchaseRequestStatus::ForPremisesScreening => RoleSlug::PremisesStaff->value,
            PurchaseRequestStatus::ForInventoryRelease,
            PurchaseRequestStatus::ForInventoryLogging,
            PurchaseRequestStatus::ForReceiving => RoleSlug::InventoryStaff->value,
            PurchaseRequestStatus::ForProcurement => $purchaseRequest->request_type === 'PREMISES'
                ? RoleSlug::PremisesStaff->value
                : RoleSlug::ItStaff->value,
            PurchaseRequestStatus::ForCanvassing => RoleSlug::CanvassingStaff->value,
            PurchaseRequestStatus::ForSupplierApproval => RoleSlug::President->value,
            PurchaseRequestStatus::AwaitingPresidentFinalApproval => RoleSlug::Purchasing->value,
            PurchaseRequestStatus::ForTlApproval => RoleSlug::TeamLeader->value,
            PurchaseRequestStatus::ForBudgetProcessing => RoleSlug::Purchasing->value,
            PurchaseRequestStatus::ForBudgetMaker,
            PurchaseRequestStatus::ForBudgetChecker,
            PurchaseRequestStatus::ForBudgetProceeder => RoleSlug::Purchasing->value,
            PurchaseRequestStatus::ForPayment => RoleSlug::Finance->value,
            default => null,
        };

        return $slug === null ? null : Role::query()->where('slug', $slug)->value('id');
    }

    private function recordAudit(
        PurchaseRequest $purchaseRequest,
        User $user,
        string $action,
        ?PurchaseRequestStatus $previousStatus,
        PurchaseRequestStatus $newStatus,
        ?string $remarks,
        array $metadata = [],
    ): void {
        $purchaseRequest->auditLogs()->create([
            'user_id' => $user->id,
            'action' => $action,
            'previous_status' => $previousStatus?->value,
            'new_status' => $newStatus->value,
            'remarks' => $remarks,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => ['role' => $user->activeRole()?->slug, ...$metadata],
        ]);
    }
}
