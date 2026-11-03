<?php

namespace App\Support;

use App\Enums\PurchaseOrderApprovalStage;
use App\Models\PurchaseOrder;

final class PurchaseOrderWorkflowTracker
{
    /** @return array{current: string, current_key: string, steps: list<array<string, mixed>>} */
    public static function for(PurchaseOrder $order): array
    {
        $order->loadMissing([
            'approvals.actor:id,name',
            'approvals.assignedUser:id,name',
            'purchaseRequest.budgetRequest.maker:id,name',
            'purchaseRequest.budgetRequest.finalApprover:id,name',
            'purchaseRequest.payments.recorder:id,name',
            'purchaseRequest.goodsReceipts.receiver:id,name',
            'purchaseRequest.inventoryItems.logger:id,name',
        ]);

        $request = $order->purchaseRequest;
        $budget = $request?->budgetRequest;
        $payment = $request?->payments?->sortByDesc('created_at')?->first();
        $receipt = $request?->goodsReceipts?->sortByDesc('received_at')?->first();
        $inventory = $request?->inventoryItems;
        $latestInventory = $inventory?->sortByDesc('logged_at')?->first();

        $approvalSteps = [
            [PurchaseOrderApprovalStage::CheckedBy, 'PO checking', 'PO Checker', ['FOR_ACKNOWLEDGEMENT', 'FOR_APPROVAL', 'APPROVED', 'ISSUED']],
            [PurchaseOrderApprovalStage::NotedBy, 'PO noting', 'PO Noter', ['FOR_APPROVAL', 'APPROVED', 'ISSUED']],
            [PurchaseOrderApprovalStage::ApprovedBy, 'PO approval', 'PO Approver', ['APPROVED', 'ISSUED']],
        ];
        $steps = [];
        foreach ($approvalSteps as [$stage, $label, $role, $advancedStatuses]) {
            $approval = $order->approvalFor($stage);
            $rejected = $approval?->status === 'REJECTED'
                || ($stage === PurchaseOrderApprovalStage::ApprovedBy && $order->status === 'REJECTED');
            $complete = $approval?->status === 'APPROVED'
                || in_array($order->status, $advancedStatuses, true);
            $steps[] = self::step(
                $label,
                $role,
                $rejected ? 'rejected' : ($complete ? 'complete' : 'waiting'),
                $approval?->actor?->name ?? $approval?->assignedUser?->name,
                $approval?->acted_at ?? $approval?->signed_at,
                $approval?->remarks,
            );
        }

        $steps[] = self::step(
            'Budget Request preparation',
            'Budget Maker',
            $budget?->maker_approved_at ? 'complete' : 'waiting',
            $budget?->maker?->name,
            $budget?->maker_approved_at,
            $budget?->budget_request_number,
        );
        $steps[] = self::step(
            'Budget final approval',
            'Purchasing final approver',
            $budget?->final_approval_status === 'REJECTED' ? 'rejected' : ($budget?->final_approval_status === 'APPROVED' ? 'complete' : 'waiting'),
            $budget?->finalApprover?->name,
            $budget?->final_approval_at,
            $budget?->rejection_reason,
        );
        $steps[] = self::step(
            'Payment',
            'Finance',
            $payment ? 'complete' : 'waiting',
            $payment?->recorder?->name,
            $payment?->created_at,
            $payment?->payment_code,
        );
        $steps[] = self::step(
            'Goods receiving',
            'Receiving / Inventory',
            $receipt ? 'complete' : 'waiting',
            $receipt?->receiver?->name,
            $receipt?->received_at,
            $receipt?->receipt_number,
        );
        $steps[] = self::step(
            'Inventory logging',
            'Inventory',
            $inventory?->isNotEmpty() ? 'complete' : 'waiting',
            $latestInventory?->logger?->name,
            $latestInventory?->logged_at,
            $inventory?->isNotEmpty() ? $inventory->count().' item(s) logged' : null,
        );

        $rejected = collect($steps)->firstWhere('state', 'rejected');
        if ($rejected) {
            return ['current' => $rejected['label'].' rejected', 'current_key' => 'rejected', 'steps' => $steps];
        }

        $current = 'Completed through inventory';
        $currentKey = 'completed';
        foreach ($steps as &$step) {
            if ($step['state'] === 'waiting') {
                $step['state'] = 'current';
                $current = 'Awaiting '.$step['label'];
                $currentKey = match ($step['label']) {
                    'PO checking' => 'checking',
                    'PO noting' => 'noting',
                    'PO approval' => 'po-approval',
                    'Budget Request preparation' => 'budget-maker',
                    'Budget final approval' => 'budget-approval',
                    'Payment' => 'payment',
                    'Goods receiving' => 'receiving',
                    'Inventory logging' => 'inventory',
                };
                break;
            }
        }
        unset($step);

        return ['current' => $current, 'current_key' => $currentKey, 'steps' => $steps];
    }

    /** @return array{label: string, role: string, state: string, actor: ?string, at: mixed, note: ?string} */
    private static function step(string $label, string $role, string $state, ?string $actor, mixed $at, ?string $note): array
    {
        return compact('label', 'role', 'state', 'actor', 'at', 'note');
    }
}
