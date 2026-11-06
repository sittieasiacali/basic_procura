<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VoidedMonitoringController extends Controller
{
    public function __invoke(Request $request): View
    {
        $statuses = [PurchaseRequestStatus::WithdrawalPending->value, PurchaseRequestStatus::Voided->value];
        $filters = $request->validate([
            'voided_search' => ['nullable', 'string', 'max:200'],
            'voided_status' => ['nullable', Rule::in($statuses)],
            'voided_from' => ['nullable', 'date'],
            'voided_to' => ['nullable', 'date', Rule::when($request->filled('voided_from'), 'after_or_equal:voided_from')],
        ]);
        $search = trim($filters['voided_search'] ?? '');

        // One register for every Purchasing Team workflow tab, including withdrawals
        // stopped earlier in the process. Acknowledgment stays with the handling staff.
        $voidedMonitoring = PurchaseRequest::query()
            ->whereIn('status', filled($filters['voided_status'] ?? null) ? [$filters['voided_status']] : $statuses)
            // Drafts voided by their requestor never reached staff.
            ->where(fn (Builder $query) => $query->whereNull('withdrawal_stage')->orWhere('withdrawal_stage', '!=', PurchaseRequestStatus::Draft->value))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $query) => $query
                    ->where('request_number', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhere('withdrawal_reason', 'like', $term)
                    ->orWhereHas('requestor', fn (Builder $requestor) => $requestor->where('name', 'like', $term)));
            })
            ->when($filters['voided_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('withdrawn_at', '>=', $date))
            ->when($filters['voided_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('withdrawn_at', '<=', $date))
            ->with([
                'requestor:id,name,email',
                'withdrawnBy:id,name',
                'withdrawalAcknowledgedBy:id,name,email,role_id',
                'withdrawalAcknowledgedBy.role:id,name',
                'assignedUser:id,name,email,role_id',
                'assignedUser.role:id,name',
                'withdrawalRole:id,name',
                'auditLogs' => fn ($query) => $query
                    ->whereIn('action', ['WITHDRAWAL_REQUESTED', 'WITHDRAWAL_ACKNOWLEDGED'])
                    ->latest('id'),
            ])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [PurchaseRequestStatus::WithdrawalPending->value])
            ->latest('withdrawn_at')
            ->latest('id')
            ->paginate(15, ['*'], 'voided_page')
            ->withQueryString();

        return view('purchase-requests.voided-monitoring', compact('voidedMonitoring'));
    }
}
