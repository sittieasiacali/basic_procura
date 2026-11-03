<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Support\PurchaseOrderWorkflowTracker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class PurchaseOrderWorkflowTrackerController extends Controller
{
    private const STAGES = [
        'all' => 'All',
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
        $stageCounts = ['all' => $allOrders->count()];
        foreach (array_keys(self::STAGES) as $key) {
            if ($key !== 'all') {
                $stageCounts[$key] = $allOrders->filter(fn (PurchaseOrder $order) => $trackers[$order->id]['current_key'] === $key)->count();
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

        return view('purchase-orders.workflow-tracker', [
            'orders' => $orders,
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
