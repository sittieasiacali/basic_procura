<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestWorkflow;
use App\Support\WorkflowSignature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PresidentApprovalController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()?->isAdministrator() || $request->user()?->hasRole(RoleSlug::President->value), 403);

        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(['all', 'pending', 'approved', 'acknowledged', 'rejected'])],
        ]);
        $tab = $validated['tab'] ?? 'pending';
        // Include the legacy level names used by seeded/older approval rows.
        $presidentLevels = [
            'PRESIDENT_SUPPLIER_APPROVAL',
            'PRESIDENT_SUPPLIER',
        ];
        $pendingStatuses = [
            PurchaseRequestStatus::ForSupplierApproval->value,
        ];

        $query = PurchaseRequest::query()
            ->with([
                'requestor:id,name',
                'responsibleRole:id,name',
                'selectedSupplier:id,name',
                'quotations' => fn ($query) => $query->with('supplier:id,name')->orderBy('price'),
            ])
            ->where(function ($query) use ($tab, $presidentLevels) {
                $pendingStatuses = [
                    PurchaseRequestStatus::ForSupplierApproval->value,
                ];

                if ($tab === 'pending') {
                    $query->whereIn('status', $pendingStatuses);

                    return;
                }

                if ($tab === 'approved') {
                    $query->whereNotIn('status', $pendingStatuses)
                        ->whereHas('approvals', function ($query) use ($presidentLevels) {
                            $query->whereIn('approval_level', $presidentLevels)
                                ->where('action', 'like', '%APPROV%');
                        })->whereDoesntHave('approvals', function ($query) use ($presidentLevels) {
                            $query->whereIn('approval_level', $presidentLevels)
                                ->where(function ($query) {
                                    $query->where('action', 'like', '%REJECT%')
                                        ->orWhere('action', 'like', '%RETURN%');
                                });
                        });

                    return;
                }

                if ($tab === 'acknowledged') {
                    $query->whereHas('approvals', fn ($approval) => $approval->where('action', 'PRESIDENT_NO_QUOTATION_ACKNOWLEDGED'));

                    return;
                }

                if ($tab === 'rejected') {
                    $query->whereNotIn('status', $pendingStatuses)
                        ->whereHas('approvals', function ($query) use ($presidentLevels) {
                            $query->whereIn('approval_level', $presidentLevels)
                                ->where(function ($query) {
                                    $query->where('action', 'like', '%REJECT%')
                                        ->orWhere('action', 'like', '%RETURN%');
                                });
                        });

                    return;
                }

                $query->whereIn('status', $pendingStatuses)
                    ->orWhereHas('approvals', fn ($query) => $query->whereIn('approval_level', $presidentLevels));
            })
            ->latest('updated_at');

        // President review is a shared role queue, including older handoffs with a stale role id.
        $summaryQuery = PurchaseRequest::query();

        return view('president-approvals.index', [
            'requests' => $query->paginate(15)->withQueryString(),
            'tab' => $tab,
            'summary' => [
                'all_pending' => (clone $summaryQuery)->whereIn('status', $pendingStatuses)->count(),
                'supplier' => (clone $summaryQuery)->where('status', PurchaseRequestStatus::ForSupplierApproval->value)->count(),
                // These checkpoints are owned by Purchasing/Finance in the current workflow.
                'purchase_order' => 0,
                'budget' => 0,
                'final' => 0,
            ],
        ]);
    }

    public function supplierDecision(
        Request $request,
        PurchaseRequest $purchaseRequest,
        PurchaseRequestWorkflow $workflow,
    ): RedirectResponse {
        $validated = $request->validate([
            'decision' => ['required', 'in:APPROVE,REJECT,ACKNOWLEDGE'],
            'quotation_id' => ['required_if:decision,APPROVE', 'nullable', 'integer'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            ...WorkflowSignature::rules(),
        ]);
        WorkflowSignature::assertValid($request, $validated);

        DB::transaction(function () use ($request, $purchaseRequest, $workflow, $validated) {
            $lockedRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $this->authorize('reviewPresident', $lockedRequest);

            if ($lockedRequest->status !== PurchaseRequestStatus::ForSupplierApproval) {
                throw ValidationException::withMessages(['status' => 'This request is no longer awaiting President supplier approval.']);
            }

            if ($validated['decision'] === 'ACKNOWLEDGE') {
                if ($lockedRequest->quotations()->exists()) {
                    throw ValidationException::withMessages(['decision' => 'This request has supplier quotations. Select a quotation to approve, or return it to Canvassing.']);
                }
                $workflow->transition(
                    $lockedRequest,
                    PurchaseRequestStatus::ForCanvassing,
                    $request->user(),
                    'PRESIDENT_NO_QUOTATION_ACKNOWLEDGED',
                    $validated['remarks'] ?? 'President acknowledged the request without supplier pricing. Purchasing can follow up with quotation details.',
                    'PRESIDENT_SUPPLIER_APPROVAL',
                    ['without_quotation' => true, 'decision' => 'ACKNOWLEDGED'],
                    $validated['signature_name'],
                    $validated['signature_data'],
                );

                return;
            }

            if ($validated['decision'] === 'APPROVE') {
                $quotation = $lockedRequest->quotations()
                    ->with('supplier')
                    ->whereKey((int) $validated['quotation_id'])
                    ->whereHas('supplier', fn ($query) => $query->where('is_active', true))
                    ->first();

                if (! $quotation) {
                    throw ValidationException::withMessages([
                        'quotation_id' => 'Select an active quotation submitted for this request.',
                    ]);
                }

                $lockedRequest->update([
                    'selected_supplier_id' => $quotation->supplier_id,
                ]);
                $lockedRequest->quotations()->update([
                    'is_approved' => false,
                    'approved_by' => null,
                    'approved_at' => null,
                ]);
                $lockedRequest->quotations()->where('supplier_id', $quotation->supplier_id)->update([
                    'is_approved' => true,
                    'approved_by' => $request->user()->id,
                    'approved_at' => now(),
                ]);
                $recommendation = $lockedRequest->quotations()
                    ->with('supplier')
                    ->where('is_recommended', true)
                    ->first();

                $workflow->transition(
                    $lockedRequest,
                    PurchaseRequestStatus::ForBudgetProcessing,
                    $request->user(),
                    'PRESIDENT_SUPPLIER_APPROVAL_APPROVED',
                    $validated['remarks'] ?? 'President approved the selected supplier quotation.',
                    'PRESIDENT_SUPPLIER_APPROVAL',
                    [
                        'selected_supplier_id' => $quotation->supplier_id,
                        'selected_supplier' => $quotation->supplier?->name,
                        'purchasing_recommendation' => $recommendation?->supplier?->name,
                        'purchasing_recommendation_quotation_id' => $recommendation?->id,
                        'purchasing_recommendation_amount' => $recommendation?->price,
                    ],
                    $validated['signature_name'],
                    $validated['signature_data'],
                );

                return;
            }

            $lockedRequest->update([
                'selected_supplier_id' => null,
            ]);
            $lockedRequest->quotations()->update([
                'is_approved' => false,
                'approved_by' => null,
                'approved_at' => null,
            ]);

            $workflow->transition(
                $lockedRequest,
                PurchaseRequestStatus::ForCanvassing,
                $request->user(),
                'PRESIDENT_SUPPLIER_APPROVAL_REJECTED',
                $validated['remarks'],
                'PRESIDENT_SUPPLIER_APPROVAL',
                ['decision' => 'RETURNED_TO_CANVASSING'],
                $validated['signature_name'],
                $validated['signature_data'],
            );
        });

        // Land on the request, which closes to a receipt naming the next stage.
        return ($request->boolean('_embedded') ? redirect()->away(\App\Support\WorkflowUrl::route('workflow.done')) : redirect()->route('purchase-requests.show', $purchaseRequest))
            ->with('success', $validated['decision'] === 'ACKNOWLEDGE'
                ? 'Request acknowledged without supplier pricing and returned to Purchasing for follow-up.'
                : 'President decision recorded.');
    }

}
