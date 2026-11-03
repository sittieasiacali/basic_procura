<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\BudgetRequest;
use App\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BudgetRequestQueueController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'br_search' => ['nullable', 'string', 'max:200'],
            'transaction_type' => ['nullable', Rule::in(['ONLINE', 'CASH', 'CHECK'])],
            'priority' => ['nullable', Rule::in(array_keys(PurchaseRequest::PRIORITY_LEVELS))],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $queue = PurchaseRequest::query()
            ->visibleTo($request->user())
            ->readyForBudgetRequest()
            ->when(trim($filters['search'] ?? ''), function (Builder $query, string $search): void {
                $term = '%'.addcslashes(trim($search), '%_\\').'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('request_number', 'like', $term)
                        ->orWhere('title', 'like', $term)
                        ->orWhere('department_campaign', 'like', $term)
                        ->orWhereHas('purchaseOrder', fn (Builder $order) => $order->where('po_number', 'like', $term))
                        ->orWhereHas('requestor', fn (Builder $requestor) => $requestor->where('name', 'like', $term));
                });
            })
            ->when($filters['priority'] ?? null, fn ($query, string $priority) => $query->where('priority_level', $priority))
            ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->where(function ($query) use ($date) {
                $query->whereDate('purchase_requests.updated_at', '>=', $date)
                    ->orWhereHas('purchaseOrder', fn ($order) => $order->whereDate('approved_at', '>=', $date));
            }))
            ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->where(function ($query) use ($date) {
                $query->whereDate('purchase_requests.updated_at', '<=', $date)
                    ->orWhereHas('purchaseOrder', fn ($order) => $order->whereDate('approved_at', '<=', $date));
            }))
            ->with([
                'requestor.campaign',
                'teamLeader.campaign',
                'items',
                'selectedSupplier',
                'purchaseOrder.supplier',
                'quotations',
            ])
            ->withMax('purchaseOrder as po_approved_at', 'approved_at')
            ->orderByDesc('po_approved_at')
            ->latest('updated_at')
            ->paginate(20)
            ->withQueryString();

        $budgetRequests = BudgetRequest::query()
            ->with(['campaign', 'custodian', 'requester', 'lines.purchaseOrder.supplier', 'lines.purchaseOrder.purchaseRequest', 'lines.campaign', 'payments', 'purchaseRequest'])
            ->when($filters['transaction_type'] ?? null, fn ($query, string $type) => $query->where('payment_method', $type))
            ->registerSearch(trim($filters['br_search'] ?? ''))
            ->when(trim($filters['search'] ?? ''), function (Builder $query, string $search): void {
                $term = '%'.addcslashes(trim($search), '%_\\').'%';
                $query->where(fn (Builder $query) => $query
                    ->where('budget_request_number', 'like', $term)
                    ->orWhereHas('purchaseRequest', fn (Builder $request) => $request
                        ->where('request_number', 'like', $term)
                        ->orWhere('title', 'like', $term)
                        ->orWhereHas('requestor', fn (Builder $requestor) => $requestor->where('name', 'like', $term)))
                    ->orWhereHas('purchaseRequests', fn (Builder $request) => $request
                        ->where('request_number', 'like', $term)
                        ->orWhere('title', 'like', $term)
                        ->orWhereHas('requestor', fn (Builder $requestor) => $requestor->where('name', 'like', $term)))
                    ->orWhereHas('lines.purchaseOrder', fn (Builder $order) => $order->where('po_number', 'like', $term)));
            })
            ->when($filters['priority'] ?? null, fn (Builder $query, string $priority) => $query->where(fn (Builder $query) => $query
                ->whereHas('purchaseRequest', fn (Builder $request) => $request->where('priority_level', $priority))
                ->orWhereHas('purchaseRequests', fn (Builder $request) => $request->where('priority_level', $priority))))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('updated_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('updated_at', '<=', $date))
            ->latest('created_at')
            ->paginate(15, ['*'], 'history_page')
            ->withQueryString();

        return view('budget-requests.index', compact('queue', 'budgetRequests', 'filters'));
    }

    public function updatePriority(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $validated = $request->validate([
            'priority_level' => ['required', Rule::in(array_keys(PurchaseRequest::PRIORITY_LEVELS))],
        ]);

        $screeningTeam = $request->user()->hasAnyRole([RoleSlug::ItStaff->value, RoleSlug::PremisesStaff->value]);

        DB::transaction(function () use ($request, $purchaseRequest, $validated, $screeningTeam): void {
            $lockedRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);

            if ($screeningTeam) {
                $this->authorize('setScreeningPriority', $lockedRequest);
            } else {
                abort_unless($request->user()->hasAnyRole([RoleSlug::BudgetMaker->value, RoleSlug::Admin->value]), 403);

                if ($lockedRequest->status !== PurchaseRequestStatus::ForBudgetProcessing
                    || ! $lockedRequest->readyForBudgetRequest()->whereKey($lockedRequest->id)->exists()) {
                    throw ValidationException::withMessages([
                        'priority_level' => 'Priority can only be recorded by the Budget Maker before Budget Request preparation.',
                    ]);
                }
            }

            $previousPriority = $lockedRequest->priority_level;
            $lockedRequest->forceFill(['priority_level' => $validated['priority_level']])->save();

            if ($previousPriority !== $validated['priority_level']) {
                $lockedRequest->auditLogs()->create([
                    'user_id' => $request->user()->id,
                    'action' => 'PRIORITY_LEVEL_UPDATED',
                    'previous_status' => $lockedRequest->status->value,
                    'new_status' => $lockedRequest->status->value,
                    'remarks' => 'Priority changed from '.(PurchaseRequest::priorityLabel($previousPriority) ?? 'Not recorded').' to '.PurchaseRequest::priorityLabel($validated['priority_level']).'.',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'metadata' => [
                        'previous_priority' => $previousPriority,
                        'priority_level' => $validated['priority_level'],
                        'role' => $request->user()->role?->slug,
                    ],
                ]);
            }
        });

        if ($screeningTeam) {
            return redirect()->route('purchase-requests.show', $purchaseRequest)
                ->with('success', 'Priority level updated.');
        }

        return redirect()->route('budget.index')
            ->with('success', 'Budget Maker priority level recorded successfully.');
    }
}
