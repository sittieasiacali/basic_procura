<?php

namespace App\Support;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class PurchaseOrderActionQueue
{
    /**
     * Return the Purchase Requests which are actionable by this user's assigned
     * PO stage. A null result means that the user has no PO workflow assignment.
     */
    public static function for(User $user): ?Builder
    {
        // The creator has canvassing and budget work too, not only a PO signer queue.
        if ($user->hasRole(RoleSlug::PurchasingTeam->value)) {
            return null;
        }

        $stages = collect(PurchaseOrderApprovalStage::cases())
            ->filter(fn (PurchaseOrderApprovalStage $stage): bool => $user->hasPoRole($stage));

        if ($stages->isEmpty()) {
            return null;
        }

        return PurchaseRequest::query()->where(function (Builder $queue) use ($stages, $user): void {
            foreach ($stages as $stage) {
                $queue->orWhere(function (Builder $request) use ($stage, $user): void {
                    if ($stage === PurchaseOrderApprovalStage::PreparedBy) {
                        // The preparer signs while creating the PO. Existing POs are
                        // therefore historical/recovery records, not new dashboard actions.
                        $request->where(fn (Builder $eligible) => $eligible->eligibleForPurchaseOrderPreparation());

                        return;
                    }

                    self::whereStageIsActionable($request, $stage, $user->id);
                });
            }
        });
    }

    private static function whereStageIsActionable(
        Builder $request,
        PurchaseOrderApprovalStage $stage,
        int $userId,
    ): void {
        $previousStages = array_slice(
            PurchaseOrderApprovalStage::cases(),
            0,
            array_search($stage, PurchaseOrderApprovalStage::cases(), true),
        );

        $request->whereHas('purchaseOrder', function (Builder $order) use ($stage, $userId, $previousStages): void {
            $order->whereNotIn('status', ['APPROVED', 'REJECTED', 'CANCELLED'])
                ->whereHas('approvals', fn (Builder $approval) => $approval
                    ->where('stage', $stage->value)
                    ->when($stage !== PurchaseOrderApprovalStage::NotedBy, fn (Builder $assigned) => $assigned->where('assigned_user_id', $userId))
                    ->when($stage === PurchaseOrderApprovalStage::NotedBy, fn (Builder $noted) => $noted->where(fn (Builder $available) => $available
                        ->whereNull('signed_at')->orWhere('acted_by', $userId)))
                    ->where(fn (Builder $incomplete) => self::whereIncompleteStage($incomplete)));

            foreach ($previousStages as $previousStage) {
                $order->whereHas('approvals', fn (Builder $approval) => $approval
                    ->where('stage', $previousStage->value)
                    ->where(fn (Builder $complete) => self::whereCompletedStage($complete)));
            }
        });
    }

    /** @param list<string> $statuses */
    public static function whereCompletedStage(Builder $approval, array $statuses = ['APPROVED']): void
    {
        $approval->whereIn('status', $statuses)
            ->whereNotNull('signature_name')
            ->where('signature_name', '!=', '')
            ->whereNotNull('signature_data')
            ->where('signature_data', 'like', 'data:image/png;base64,%')
            ->whereRaw('LENGTH(signature_data) >= ?', [110])
            ->whereNotNull('signed_at')
            ->whereNotNull('acted_at')
            ->whereColumn('acted_at', '>=', 'signed_at')
            ->whereNotNull('acted_by')
            ->where(fn (Builder $actor) => $actor->where('stage', PurchaseOrderApprovalStage::NotedBy->value)
                ->orWhereColumn('acted_by', 'assigned_user_id'));
    }

    private static function whereIncompleteStage(Builder $approval): void
    {
        $approval->where(function (Builder $incomplete): void {
            $incomplete->whereNull('status')
                ->orWhere('status', '!=', 'APPROVED')
                ->orWhereNull('signature_name')
                ->orWhere('signature_name', '')
                ->orWhereNull('signature_data')
                ->orWhere('signature_data', 'not like', 'data:image/png;base64,%')
                ->orWhereRaw('LENGTH(signature_data) < ?', [110])
                ->orWhereNull('signed_at')
                ->orWhereNull('acted_at')
                ->orWhereColumn('acted_at', '<', 'signed_at')
                ->orWhereNull('acted_by')
                ->orWhere(fn (Builder $wrongActor) => $wrongActor
                    ->where('stage', '!=', PurchaseOrderApprovalStage::NotedBy->value)
                    ->whereColumn('acted_by', '!=', 'assigned_user_id'));
        });
    }
}
