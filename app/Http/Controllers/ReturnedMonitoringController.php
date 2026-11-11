<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReturnedMonitoringController extends Controller
{
    public function __invoke(Request $request): View
    {
        $filters = $request->validate([
            'returned_search' => ['nullable', 'string', 'max:200'],
            'returned_status' => ['nullable', Rule::in(['RETURNED', 'RESUBMITTED'])],
            'returned_from' => ['nullable', 'date'],
            'returned_to' => ['nullable', 'date', Rule::when($request->filled('returned_from'), 'after_or_equal:returned_from')],
        ]);
        $search = trim($filters['returned_search'] ?? '');
        $returnedMonitoring = PurchaseRequest::query()->visibleTo($request->user())
            ->whereHas('latestReturnApproval')
            ->when(($filters['returned_status'] ?? '') === 'RETURNED', fn ($query) => $query->where('status', PurchaseRequestStatus::Returned))
            ->when(($filters['returned_status'] ?? '') === 'RESUBMITTED', fn ($query) => $query->whereNotIn('status', [PurchaseRequestStatus::Returned, PurchaseRequestStatus::Voided, PurchaseRequestStatus::WithdrawalPending])->whereHas('auditLogs', fn ($log) => $log->where('action', 'RESUBMITTED_FOR_SCREENING')))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn ($query) => $query->where('request_number', 'like', $term)->orWhere('title', 'like', $term)
                    ->orWhereHas('requestor', fn ($owner) => $owner->where('name', 'like', $term))
                    ->orWhereHas('latestReturnApproval', fn ($approval) => $approval->where('remarks', 'like', $term)
                        ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $term))));
            })
            ->when($filters['returned_from'] ?? null, fn ($query, $date) => $query->whereHas('latestReturnApproval', fn ($approval) => $approval->whereDate('acted_at', '>=', $date)))
            ->when($filters['returned_to'] ?? null, fn ($query, $date) => $query->whereHas('latestReturnApproval', fn ($approval) => $approval->whereDate('acted_at', '<=', $date)))
            ->with(['requestor:id,name', 'latestReturnApproval.user:id,name', 'responsibleRole:id,name', 'purchaseOrder:id,purchase_request_id,po_number'])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [PurchaseRequestStatus::Returned->value])
            ->latest('updated_at')->latest('id')->paginate(15, ['*'], 'returned_page')->withQueryString();

        return view('purchase-requests.returned-monitoring', compact('returnedMonitoring'));
    }
}
