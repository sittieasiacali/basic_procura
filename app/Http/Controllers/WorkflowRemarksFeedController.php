<?php

namespace App\Http\Controllers;

use App\Models\PurchaseRequest;
use App\Support\WorkflowRemarks;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Latest workflow remarks across Purchase Orders, polled by the dashboard's Live remarks dialog. */
class WorkflowRemarksFeedController extends Controller
{
    private const REQUESTS = 40;
    private const REMARKS = 60;

    public function __invoke(Request $request, WorkflowRemarks $remarks): View|JsonResponse
    {
        // Remarks newer than the viewer's last visit are counted on the button and marked in the list.
        $since = rescue(fn () => $request->filled('since') ? CarbonImmutable::parse($request->string('since')->toString()) : null, null, false);
        $search = trim($request->string('search')->toString());
        // Every workflow step updates its request, so recently updated requests hold the newest remarks.
        $requests = PurchaseRequest::query()
            ->whereHas('purchaseOrder')
            ->with('purchaseOrder:id,purchase_request_id,po_number')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $query) => $query
                    ->where('request_number', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhereHas('purchaseOrder', fn (Builder $order) => $order->where('po_number', 'like', $term)));
            })
            ->latest('updated_at')
            ->limit(self::REQUESTS)
            ->get(['id', 'request_number', 'title', 'updated_at']);

        $remarks->prepareRecords($requests);
        $entries = $requests
            ->flatMap(fn (PurchaseRequest $purchaseRequest) => $remarks->forRequest($purchaseRequest->id)
                ->map(fn (array $entry) => $entry + ['order' => $purchaseRequest->purchaseOrder, 'title' => $purchaseRequest->title]))
            // The same note is often audited under two stage names; show it once.
            ->unique(fn (array $entry) => $entry['request'].'|'.$entry['actor'].'|'.$entry['text'])
            ->sortByDesc(fn (array $entry) => $entry['at']?->getTimestamp() ?? 0)
            ->take(self::REMARKS)
            ->values();

        $isNew = fn (array $entry) => $since && $entry['at'] && $entry['at']->greaterThan($since);

        if ($request->boolean('count')) {
            return response()->json(['new' => $entries->filter($isNew)->count()]);
        }

        return view('purchase-orders.partials.remarks-feed', [
            'groups' => $entries->map(fn (array $entry) => $entry + ['new' => $isNew($entry)])
                ->groupBy(fn (array $entry) => match (true) {
                    ! $entry['at'] => 'Earlier',
                    $entry['at']->isToday() => 'Today',
                    $entry['at']->isYesterday() => 'Yesterday',
                    default => $entry['at']->format('M j, Y'),
                }),
            'search' => $search,
        ]);
    }
}
