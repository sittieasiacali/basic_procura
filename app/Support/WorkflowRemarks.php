<?php

namespace App\Support;

use App\Models\BudgetRequest;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\RequestApproval;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use SplObjectStorage;

class WorkflowRemarks
{
    private array $entries = [];
    private ?object $requestScope = null;

    /** Batch only the requests represented by the current page's records. */
    public function prepare(View $view): void
    {
        $this->prepareRecords($view->getData());
    }

    public function prepareRecords(iterable $records): void
    {
        if ($this->requestScope !== request()) {
            $this->entries = [];
            $this->requestScope = request();
        }
        $ids = [];
        $seen = new SplObjectStorage;
        $visit = function ($value) use (&$visit, &$ids, $seen): void {
            if ($value instanceof Model) {
                if ($seen->contains($value)) {
                    return;
                }
                $seen->attach($value);
                if ($value instanceof PurchaseRequest) {
                    $ids[] = $value->id;
                } elseif ($value instanceof PurchaseOrder || $value instanceof BudgetRequest || $value instanceof Payment || $value instanceof RequestApproval) {
                    $ids[] = $value->purchase_request_id;
                }
                $visit($value->getRelations());
            } elseif ($value instanceof Paginator) {
                $visit($value->items());
            } elseif (is_array($value) || $value instanceof Collection) {
                foreach ($value as $item) {
                    $visit($item);
                }
            }
        };
        $visit($records);
        $this->load(array_filter(array_unique($ids)));
    }

    public function forRequest(?int $id): Collection
    {
        if (! $id) {
            return collect();
        }
        $this->load([$id]);

        return $this->entries[$id] ?? collect();
    }

    public function textForRequest(?int $id): string
    {
        return $this->forRequest($id)->map(fn ($entry) => $entry['request'].' · '.$entry['stage'].' · '.$entry['actor'].': '.$entry['text'])->join("\n");
    }

    private function load(array $ids): void
    {
        $ids = array_values(array_filter($ids, fn ($id) => ! array_key_exists($id, $this->entries)));
        if ($ids === []) {
            return;
        }
        foreach ($ids as $id) {
            $this->entries[$id] = collect();
        }
        // Select note fields explicitly: signatures and attachment contents are not needed in lists.
        $requests = PurchaseRequest::query()->whereKey($ids)->with([
            'requestor:id,name',
            'approvals:id,purchase_request_id,user_id,approval_level,action,remarks,acted_at',
            'approvals.user:id,name',
            'auditLogs:id,auditable_type,auditable_id,user_id,action,remarks,created_at',
            'auditLogs.user:id,name',
            'screening:id,purchase_request_id,screened_by,team,evidence,decided_at',
            'screening.screener:id,name',
            'quotations:id,purchase_request_id,supplier_id,created_by,remarks,is_recommended,created_at,updated_at',
            'quotations.supplier:id,name',
            'quotations.creator:id,name',
            'purchaseOrder:id,purchase_request_id,created_by,remarks,created_at,checked_by,checked_at,checker_remarks,acknowledged_by,acknowledged_at,acknowledgement_remarks,approved_by,approved_at,approval_remarks,rejection_reason',
            'purchaseOrder.creator:id,name',
            'purchaseOrder.checker:id,name',
            'purchaseOrder.acknowledger:id,name',
            'purchaseOrder.approver:id,name',
            'purchaseOrder.approvals:id,purchase_order_id,stage,acted_by,remarks,acted_at,signed_at',
            'purchaseOrder.approvals.actor:id,name',
            'budgetRequest:budget_requests.id,budget_requests.purchase_request_id,budget_requests.maker_user_id,budget_requests.remarks,budget_requests.rejection_reason,budget_requests.final_approval_by,budget_requests.final_approval_at,budget_requests.created_at',
            'budgetRequest.maker:id,name',
            'budgetRequest.finalApprover:id,name',
            'budgetRequest.lines:id,budget_request_id,purchase_order_id,remarks,created_at',
            'payments:id,purchase_request_id,recorded_by,details,paid_at,created_at',
            'payments.recorder:id,name',
            'goodsReceipts:id,purchase_request_id,received_by,remarks,received_at',
            'goodsReceipts.receiver:id,name',
            'endorsements:id,purchase_request_id,endorsed_by,remarks,endorsed_at',
            'endorsements.endorser:id,name',
            'inventoryItems:id,purchase_request_id',
            'inventoryItems.releases:id,inventory_item_id,released_by,remarks,released_at,claimed_by,claim_remarks,claimed_at',
            'inventoryItems.releases.releaser:id,name',
            'inventoryItems.releases.claimant:id,name',
        ])->get(['id', 'request_number', 'requestor_id', 'remarks', 'created_at']);

        foreach ($requests as $request) {
            $entries = collect();
            $add = function (string $stage, ?string $text, ?string $actor, $at, ?string $action = null) use ($entries, $request): void {
                if (blank($text) || strtoupper(trim($text)) === 'N/A') {
                    return;
                }
                $entries->push(['stage' => $stage, 'text' => $text, 'actor' => $actor ?: 'System', 'at' => $at, 'action' => $action, 'request' => $request->request_number]);
            };
            $add('Request', $request->remarks, $request->requestor?->name, $request->created_at);
            foreach ($request->approvals as $approval) {
                $add($this->label($approval->approval_level), $approval->remarks, $approval->user?->name, $approval->acted_at, $approval->action);
            }
            // Approval transitions are also audited. Show their remarks once, preserving distinct later events.
            foreach ($request->auditLogs as $log) {
                $duplicate = $request->approvals->contains(fn ($approval) => $approval->action === $log->action
                    && $approval->user_id === $log->user_id && $approval->remarks === $log->remarks
                    && $approval->acted_at?->format('Y-m-d H:i:s') === $log->created_at?->format('Y-m-d H:i:s'));
                if (! $duplicate) {
                    $add($this->label($log->action), $log->remarks, $log->user?->name, $log->created_at, $log->action);
                }
            }
            if ($request->screening) {
                $add($request->screening->team.' screening', $request->screening->evidence, $request->screening->screener?->name, $request->screening->decided_at);
            }
            foreach ($request->quotations as $quotation) {
                $stage = $quotation->is_recommended ? 'Supplier recommendation' : 'Quotation';
                $add($stage.' · '.$quotation->supplier?->name, $quotation->remarks, $quotation->creator?->name, $quotation->updated_at);
            }
            if ($request->purchaseOrder) {
                $add('Purchase Order', $request->purchaseOrder->remarks, $request->purchaseOrder->creator?->name, $request->purchaseOrder->created_at);
                foreach ($request->purchaseOrder->approvals as $approval) {
                    $add($this->label($approval->stage->value), $approval->remarks, $approval->actor?->name, $approval->acted_at ?? $approval->signed_at);
                }
                foreach ([
                    ['PO_CHECKED_BY', $request->purchaseOrder->checker_remarks, $request->purchaseOrder->checker?->name, $request->purchaseOrder->checked_at],
                    ['PO_NOTED_BY', $request->purchaseOrder->acknowledgement_remarks, $request->purchaseOrder->acknowledger?->name, $request->purchaseOrder->acknowledged_at],
                    ['PO_APPROVED_BY', $request->purchaseOrder->approval_remarks, $request->purchaseOrder->approver?->name, $request->purchaseOrder->approved_at],
                ] as [$stage, $text, $actor, $at]) {
                    if (! $request->purchaseOrder->approvals->contains(fn ($approval) => $approval->stage->value === $stage && $approval->remarks === $text)) {
                        $add($this->label($stage), $text, $actor, $at);
                    }
                }
                $add('PO return reason', $request->purchaseOrder->rejection_reason, $request->purchaseOrder->approver?->name, $request->purchaseOrder->approved_at);
            }
            if ($request->budgetRequest) {
                $budget = $request->budgetRequest;
                $lines = $budget->lines->where('purchase_order_id', $request->purchaseOrder?->id);
                foreach ($lines as $line) {
                    $add('Budget preparation', $line->remarks, $budget->maker?->name, $line->created_at);
                }
                if ((int) $budget->purchase_request_id === (int) $request->id || $budget->lines->count() === 1) {
                    $add('Budget preparation', $budget->remarks, $budget->maker?->name, $budget->created_at);
                }
                $add('Budget return reason', $budget->rejection_reason, $budget->finalApprover?->name, $budget->final_approval_at);
            }
            foreach ($request->payments as $payment) {
                $add('Payment', $payment->details, $payment->recorder?->name, $payment->paid_at ?? $payment->created_at);
            }
            foreach ($request->goodsReceipts as $receipt) {
                $add('Receiving', $receipt->remarks, $receipt->receiver?->name, $receipt->received_at);
            }
            foreach ($request->endorsements as $endorsement) {
                $add('Endorsement', $endorsement->remarks, $endorsement->endorser?->name, $endorsement->endorsed_at);
            }
            foreach ($request->inventoryItems as $item) {
                foreach ($item->releases as $release) {
                    $add('Inventory release', $release->remarks, $release->releaser?->name, $release->released_at);
                    $add('Inventory claim', $release->claim_remarks, $release->claimant?->name, $release->claimed_at);
                }
            }
            $this->entries[$request->id] = $entries->unique(fn ($entry) => $entry['stage'].'|'.$entry['actor'].'|'.$entry['text'].'|'.$entry['at']?->format('Y-m-d H:i:s'))
                ->sortBy(fn ($entry) => $entry['at']?->getTimestamp() ?? 0)->values();
        }
    }

    /** Turn codes such as OM_REVIEW into "OM Review", keeping role acronyms upper-case. */
    private function label(?string $code): string
    {
        $words = explode(' ', (string) str((string) $code)->replace('_', ' ')->lower()->title());

        return implode(' ', array_map(fn ($word) => in_array(strtoupper($word), ['OM', 'PO', 'PR', 'BR', 'IT', 'HR', 'TL', 'VAT'], true) ? strtoupper($word) : $word, $words));
    }
}
