<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Support\PurchaseOrderWorkflowTracker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class PurchaseOrderWorkflowTrackerController extends Controller
{
    private const STAGES = [
        'all' => 'All',
        'returned' => 'Returned to Requestor',
        'resubmitted' => 'Resubmitted Requests',
        'withdrawal' => 'Void Pending',
        'voided' => 'Voided',
        'checking' => 'PO Checking',
        'noting' => 'PO Noting',
        'po-approval' => 'PO Approval',
        'budget-maker' => 'Budget Maker',
        'budget-approval' => 'Budget Approval',
        'payment' => 'Payment',
        'receiving' => 'Receiving',
        'inventory' => 'Inventory',
        'completed' => 'Completed',
        'rejected' => 'Rejected',
    ];

    public function __invoke(Request $request): View
    {
        $search = trim($request->string('search')->toString());
        $requestedStage = $request->string('stage')->toString();
        $stage = array_key_exists($requestedStage, self::STAGES) ? $requestedStage : 'all';
        $allOrders = PurchaseOrder::query()
            ->with([
                'supplier:id,name',
                'purchaseRequest.budgetRequest.maker:id,name',
                'purchaseRequest.budgetRequest.finalApprover:id,name',
                'purchaseRequest.payments.recorder:id,name',
                'purchaseRequest.goodsReceipts.receiver:id,name',
                'purchaseRequest.inventoryItems.logger:id,name',
                'approvals.actor:id,name',
                'approvals.assignedUser:id,name',
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $query) => $query
                    ->where('po_number', 'like', $term)
                    ->orWhereHas('purchaseRequest', fn (Builder $request) => $request
                        ->where('request_number', 'like', $term)
                        ->orWhere('title', 'like', $term)));
            })
            ->latest('created_at')
            ->latest('id')
            ->get();

        $trackers = $allOrders->mapWithKeys(fn (PurchaseOrder $order) => [
            $order->id => PurchaseOrderWorkflowTracker::for($order),
        ]);
        // Returns can occur before a PO exists; keep them visible through correction and resubmission.
        $prePoReturns = PurchaseRequest::query()->visibleTo($request->user())->whereDoesntHave('purchaseOrder')
            ->whereHas('latestReturnApproval')
            ->with(['requestor:id,name', 'latestReturnApproval.user:id,name', 'responsibleRole:id,name'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn ($query) => $query->where('request_number', 'like', $term)->orWhere('title', 'like', $term));
            })->latest('updated_at')->get();
        $returnStage = fn (PurchaseRequest $item) => match ($item->status) {
            PurchaseRequestStatus::Returned => 'returned',
            PurchaseRequestStatus::WithdrawalPending => 'withdrawal',
            PurchaseRequestStatus::Voided => 'voided',
            default => 'resubmitted',
        };
        $stageCounts = ['all' => $allOrders->count() + $prePoReturns->count()];
        foreach (array_keys(self::STAGES) as $key) {
            if ($key !== 'all') {
                $stageCounts[$key] = $allOrders->filter(fn (PurchaseOrder $order) => $trackers[$order->id]['current_key'] === $key)->count()
                    + $prePoReturns->filter(fn ($item) => $returnStage($item) === $key)->count();
            }
        }

        $matchingOrders = $stage === 'all'
            ? $allOrders
            : $allOrders->filter(fn (PurchaseOrder $order) => $trackers[$order->id]['current_key'] === $stage)->values();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $orders = new LengthAwarePaginator(
            $matchingOrders->forPage($page, 10)->values(),
            $matchingOrders->count(),
            10,
            $page,
            ['path' => route('workflow-tracker.index'), 'query' => $request->query()],
        );

        $matchingReturns = $prePoReturns->filter(fn ($item) => $stage === 'all' || $returnStage($item) === $stage)->values();
        $returnPage = LengthAwarePaginator::resolveCurrentPage('returns_page');
        $returnedRequests = new LengthAwarePaginator($matchingReturns->forPage($returnPage, 10)->values(), $matchingReturns->count(), 10, $returnPage,
            ['path' => route('workflow-tracker.index'), 'query' => $request->query(), 'pageName' => 'returns_page']);

        return view('purchase-orders.workflow-tracker', [
            'orders' => $orders,
            'returnedRequests' => $returnedRequests,
            'search' => $search,
            'stage' => $stage,
            'stageLabels' => self::STAGES,
            'stageCounts' => $stageCounts,
            'trackers' => $trackers,
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder->loadMissing(['supplier:id,name', 'purchaseRequest']);

        return view('purchase-orders.workflow-tracker-show', [
            'order' => $purchaseOrder,
            'tracker' => PurchaseOrderWorkflowTracker::for($purchaseOrder),
        ]);
    }
}
