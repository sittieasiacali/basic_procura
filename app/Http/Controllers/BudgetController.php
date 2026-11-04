<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseRequestStatus;
use App\Models\BudgetRequest;
use App\Models\Campaign;
use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestWorkflow;
use App\Support\WorkflowSignature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BudgetController extends Controller
{
    /** Standalone transaction sheet for one Budget Request (opens the print dialog with ?mode=print). */
    public function document(Request $request, BudgetRequest $budgetRequest): View|\Illuminate\Http\JsonResponse
    {
        abort_unless($budgetRequest->purchaseRequest, 404);
        $this->authorize('view', $budgetRequest->purchaseRequest);

        $budgetRequest->load([
            'requester', 'maker', 'checker', 'finalApprover', 'campaign', 'payments',
            'purchaseOrder.creator',
            'lines.requester', 'lines.campaign', 'lines.purchaseOrder.supplier', 'lines.purchaseOrder.purchaseRequest',
            'purchaseRequest.requestor', 'purchaseRequest.approvals', 'purchaseRequest.purchaseOrder.creator',
        ]);

        if ($request->boolean('preview')) {
            return response()->json([
                'number' => $budgetRequest->budget_request_number ?? 'Pending BR code',
                // The online / cash transaction sheet is shown with the payment (Finance), not here.
                'transaction_type' => 'Budget Request',
                'transaction_html' => view('budget-requests.partials.br-document-sheet', ['budget' => $budgetRequest, 'purchaseRequest' => $budgetRequest->purchaseRequest])->render(),
            ]);
        }

        return view('budget-requests.document', [
            'budget' => $budgetRequest,
            'mode' => $request->string('mode')->toString(),
        ]);
    }

    public function editPoDetails(PurchaseRequest $purchaseRequest): View
    {
        $this->authorize('manageBudget', $purchaseRequest);
        abort_unless($purchaseRequest->status === PurchaseRequestStatus::AwaitingPresidentFinalApproval
            && $purchaseRequest->purchaseOrder()->exists()
            && $purchaseRequest->budgetRequest()->exists(), 404);

        $purchaseRequest->load(['purchaseOrder.budgetCampaign', 'requestor', 'teamLeader']);

        return view('budget-requests.edit-po', [
            'purchaseRequest' => $purchaseRequest,
            'campaigns' => Campaign::query()->where('is_active', true)
                ->whereIn('name', array_keys(Campaign::BUDGET_ACCOUNT_OPTIONS))
                ->orderBy('name')->get(['id', 'name', 'account_number']),
        ]);
    }

    public function updatePoDetails(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $validated = $request->validate([
            'campaign_id' => ['required', 'integer', Rule::exists('campaigns', 'id')->where(
                fn ($query) => $query->where('is_active', true)->whereIn('name', array_keys(Campaign::BUDGET_ACCOUNT_OPTIONS)),
            )],
            'account_type' => ['required', Rule::in(['ACA', 'OTHER_PARTY', 'OWN_ACCOUNT'])],
            'transaction_type' => ['required', Rule::in(['ONLINE', 'CASH', 'CHECK'])],
            'payable_on' => ['nullable', 'date'],
            'fund_source' => ['required', Rule::in(['MAIN_FUND', 'CAMPAIGN_FUND', 'OTHER'])],
            'source_bank_account' => ['required', 'string', 'max:2000'],
            'main_fund' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:255'],
            'payment_details' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $purchaseRequest, $validated): void {
            $lockedRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $this->authorize('manageBudget', $lockedRequest);
            abort_unless($lockedRequest->status === PurchaseRequestStatus::AwaitingPresidentFinalApproval
                && $lockedRequest->budgetRequest()->exists(), 404);
            $order = $lockedRequest->purchaseOrder()->firstOrFail();
            $campaign = Campaign::query()->where('is_active', true)->findOrFail($validated['campaign_id']);
            $order->update([
                'budget_campaign_id' => $campaign->id,
                'budget_campaign_account_number' => $campaign->account_number,
                'budget_account_type' => $validated['account_type'],
                'budget_transaction_type' => $validated['transaction_type'],
                'budget_payable_on' => $validated['payable_on'] ?? null,
                'budget_fund_source' => $validated['fund_source'],
                'budget_source_bank_account' => $validated['source_bank_account'],
                'main_fund' => $validated['main_fund'],
                'requested_by' => $lockedRequest->teamLeader?->name ?? $lockedRequest->requestor?->name,
                'account_number' => filled($validated['account_number'] ?? null) ? $validated['account_number'] : $order->account_number,
                'payment_details' => filled($validated['payment_details'] ?? null) ? $validated['payment_details'] : $order->payment_details,
            ]);

            $budget = $lockedRequest->budgetRequest()->firstOrFail();
            if ((int) $budget->purchase_order_id === (int) $order->id) {
                $budget->update([
                    'campaign_id' => $campaign->id,
                    'campaign_account_number' => $campaign->account_number,
                    'account_type' => $validated['account_type'],
                    'payment_method' => $validated['transaction_type'],
                    'payable_on' => $validated['payable_on'] ?? null,
                    'fund_source' => $validated['fund_source'],
                    'source_bank_account' => $validated['source_bank_account'],
                ]);
            }
            $lockedRequest->auditLogs()->create([
                'user_id' => $request->user()->id,
                'action' => 'PURCHASE_ORDER_BUDGET_DETAILS_UPDATED',
                'previous_status' => $lockedRequest->status->value,
                'new_status' => $lockedRequest->status->value,
                'remarks' => 'Purchase Order budget details updated.',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => ['purchase_order_id' => $order->id],
            ]);
        });

        return redirect()->route('purchase-requests.show', $purchaseRequest)
            ->with('success', 'Purchase Order budget details updated.');
    }

    public function create(Request $request): View
    {
        $validated = $request->validate([
            'purchase_request_id' => ['required', 'integer', 'exists:purchase_requests,id'],
            'purchase_request_ids' => ['prohibited'],
        ]);

        $purchaseRequest = PurchaseRequest::query()
            ->visibleTo($request->user())
            ->whereKey($validated['purchase_request_id'])
            ->with(['requestor.campaign', 'teamLeader.campaign', 'items', 'selectedSupplier', 'purchaseOrder.supplier', 'purchaseOrder.budgetCampaign', 'purchaseOrder.items', 'quotations' => fn ($query) => $query->where('is_approved', true)])
            ->firstOrFail();

        $this->validateSelection($purchaseRequest);

        $campaigns = Campaign::query()
            ->where('is_active', true)
            ->whereIn('name', array_keys(Campaign::BUDGET_ACCOUNT_OPTIONS))
            ->orderBy('name')
            ->get(['id', 'name', 'account_number']);

        return view('budget-requests.create', [
            'purchaseRequest' => $purchaseRequest,
            'campaigns' => $campaigns,
        ]);
    }

    public function store(
        Request $request,
        PurchaseRequestWorkflow $workflow,
    ): RedirectResponse {
        $validateSignature = config('procurement.budget_preparation.signature_required')
            || $request->hasAny(['signature_name', 'signature_data']);
        $validated = $request->validate([
            'purchase_request_id' => ['required', 'integer', 'exists:purchase_requests,id'],
            'purchase_request_ids' => ['prohibited'],
            'priority_level' => ['sometimes', 'required', Rule::in(array_keys(PurchaseRequest::PRIORITY_LEVELS))],
            'request_campaign' => ['sometimes', 'required', 'string', 'max:255'],
            'details' => ['sometimes', 'required', 'string', 'max:2000'],
            'requested_payment_method' => ['nullable', Rule::in(['ONLINE', 'CASH', 'CHECK', 'N/A'])],
            'bank_details' => ['sometimes', 'required', 'string', 'max:2000'],
            'payable_on' => ['nullable', 'date'],
            'campaign_id' => ['required', 'integer', Rule::exists('campaigns', 'id')->where(
                fn ($query) => $query->where('is_active', true)->whereIn('name', array_keys(Campaign::BUDGET_ACCOUNT_OPTIONS)),
            )],
            // Budget Information is completed by Finance at final approval.
            ...self::budgetInformationRules(),
            'remarks' => ['nullable', 'string', 'max:2000'],
            ...($validateSignature ? WorkflowSignature::rules() : []),
        ]);
        if ($validateSignature) {
            WorkflowSignature::assertValid($request, $validated);
        }

        $purchaseRequest = DB::transaction(function () use ($request, $workflow, $validated): PurchaseRequest {
            $purchaseRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($validated['purchase_request_id']);
            $purchaseRequest->load(['requestor.campaign', 'teamLeader.campaign', 'items', 'selectedSupplier', 'purchaseOrder.supplier', 'purchaseOrder.budgetCampaign', 'purchaseOrder.items', 'quotations']);
            $this->validateSelection($purchaseRequest);
            // Without a PO this is an unsaved stand-in priced from the approved quotation; its id is null.
            $purchaseOrder = $purchaseRequest->budgetPurchaseOrder();
            $reference = $purchaseOrder->po_number ?? $purchaseRequest->request_number;
            $campaign = Campaign::query()->where('is_active', true)
                ->whereIn('name', array_keys(Campaign::BUDGET_ACCOUNT_OPTIONS))
                ->lockForUpdate()->findOrFail($validated['campaign_id']);
            $amount = (float) $purchaseOrder->total_amount;
            $priority = $validated['priority_level'] ?? $purchaseRequest->priority_level ?? 'NORMAL';
            $purchaseRequest->update(['priority_level' => $priority]);
            $validated['account_type'] ??= $purchaseOrder->budget_account_type;
            $validated['transaction_type'] ??= $purchaseOrder->budget_transaction_type;
            $validated['fund_source'] ??= $purchaseOrder->budget_fund_source;
            $validated['main_fund'] ??= $purchaseOrder->main_fund;
            $budget = BudgetRequest::query()->create([
                // The BR code is issued at the Final budget.
                'budget_request_number' => null,
                'purchase_request_id' => $purchaseRequest->id,
                'purchase_order_id' => $purchaseOrder->id,
                'campaign_id' => $campaign->id,
                'campaign_account_number' => $campaign->account_number,
                'account_type' => $validated['account_type'],
                'requested_by' => $purchaseRequest->team_leader_id ?? $purchaseRequest->requestor_id,
                'maker_user_id' => $request->user()->id,
                'maker_approved_at' => now(),
                'payment_method' => $validated['transaction_type'],
                'fund_source' => $validated['fund_source'],
                'main_fund' => $validated['main_fund'],
                'source_bank_account' => $campaign->account_number,
                'amount' => $amount,
                'remarks' => $validated['remarks'] ?? null,
                'status' => 'PENDING_FINAL_APPROVAL',
                'requested_at' => now()->toDateString(),
                'payable_on' => array_key_exists('payable_on', $validated) ? $validated['payable_on'] : $purchaseOrder->budget_payable_on,
            ]);

            if ($purchaseOrder->exists) {
                $purchaseOrder->update([
                    'budget_transaction_type' => $validated['transaction_type'],
                    'budget_fund_source' => $validated['fund_source'],
                    'main_fund' => $validated['main_fund'],
                ]);
            }
            $approvedQuotation = $purchaseRequest->quotations
                ->first(fn ($quotation) => (int) $quotation->supplier_id === (int) $purchaseOrder->supplier_id
                    && $quotation->is_approved
                    && filled($quotation->bank_details));

            $budget->allocations()->create([
                'purchase_request_id' => $purchaseRequest->id,
                'purchase_order_id' => $purchaseOrder->id,
                'amount' => $amount,
            ]);
            $budget->lines()->create([
                'purchase_order_id' => $purchaseOrder->id,
                'campaign_id' => $campaign->id,
                'source_bank_account' => $campaign->account_number,
                'requested_by_user_id' => $purchaseRequest->team_leader_id ?? $purchaseRequest->requestor_id,
                'details' => $validated['details'] ?? ($purchaseOrder->po_number ? $purchaseOrder->po_number.' — ' : '').$purchaseRequest->request_number.': '.$purchaseRequest->title,
                'amount' => $amount,
                'bank_details' => $validated['bank_details'] ?? $approvedQuotation?->bank_details,
                'priority_level' => $priority,
                'request_campaign' => $validated['request_campaign'] ?? $purchaseRequest->department_campaign,
                'requested_payment_method' => $validated['requested_payment_method'] ?? null,
                'requesting_team' => $purchaseRequest->requestor?->department ?: $purchaseRequest->department_campaign ?: 'N/A',
                'payable_on' => $budget->payable_on,
                'remarks' => $validated['remarks'] ?? null,
            ]);

            $purchaseRequest->auditLogs()->create([
                'user_id' => $request->user()->id,
                'action' => 'BUDGET_REQUEST_CREATED',
                'previous_status' => $purchaseRequest->status->value,
                'new_status' => $purchaseRequest->status->value,
                'remarks' => 'Budget Request created for '.$reference.'; its BR code is issued at the Final budget.',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'budget_request_id' => $budget->id,
                    'purchase_order_id' => $purchaseOrder->id,
                    'amount' => $amount,
                ],
            ]);

            $workflow->transition(
                $purchaseRequest,
                PurchaseRequestStatus::AwaitingPresidentFinalApproval,
                $request->user(),
                'BUDGET_REQUEST_SUBMITTED',
                $validated['remarks'] ?? 'Budget Request submitted for Purchasing final approval.',
                'BUDGET_REQUEST_PREPARATION',
                ['budget_request_id' => $budget->id, 'purchase_order_id' => $purchaseOrder->id],
                $validated['signature_name'] ?? null,
                $validated['signature_data'] ?? null,
            );

            return $purchaseRequest;
        });

        return $this->finished($request, redirect()->route('purchase-requests.show', $purchaseRequest),
            'Budget Request created. It is now waiting for Finance.');
    }

    /** Final budget: issues BR codes for the ticked Budget Requests and moves them to Payment. */
    public function finalizeForm(Request $request): View
    {
        $validated = $request->validate(['ids' => ['required', 'array', 'max:100'], 'ids.*' => ['integer']]);
        $budgetRequests = $this->finalizableBudgets($request, $validated['ids']);
        abort_if($budgetRequests->isEmpty(), 404);

        return view('budget-requests.finalize', compact('budgetRequests'));
    }

    public function finalizeBatch(Request $request, PurchaseRequestWorkflow $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'priority_level' => ['required', Rule::in(array_keys(PurchaseRequest::PRIORITY_LEVELS))],
            ...WorkflowSignature::rules(),
        ]);
        WorkflowSignature::assertValid($request, $validated);

        $budgetRequests = $this->finalizableBudgets($request, $validated['ids']);
        if ($budgetRequests->count() !== count(array_unique($validated['ids']))) {
            throw ValidationException::withMessages(['ids' => 'One or more selected Budget Requests are no longer waiting for your final budget. Reload the list and try again.']);
        }

        // One Final budget = one group BR code listing every ticked PO.
        $number = DB::transaction(function () use ($request, $workflow, $validated, $budgetRequests): string {
            $primary = $budgetRequests->first();
            $primary->absorb($budgetRequests->slice(1));
            // Priority Level is chosen here, for every PO in the group BR.
            $primary->lines()->update(['priority_level' => $validated['priority_level']]);
            PurchaseRequest::query()
                ->whereKey($budgetRequests->flatMap(fn (BudgetRequest $budget) => $budget->lines->pluck('purchaseOrder.purchase_request_id'))
                    ->push($primary->purchase_request_id)->filter()->unique()->values())
                ->update(['priority_level' => $validated['priority_level']]);
            $this->finalizeBudget($request, $primary->purchaseRequest, $workflow, $validated);

            return $primary->fresh()->budget_request_number;
        });

        $poCount = $budgetRequests->count();

        return $this->finished($request, redirect()->route('dashboard'),
            "Final budget done: {$number} issued for {$poCount} ".($poCount === 1 ? 'PO' : 'POs').'. It is ready for payment.');
    }

    /** @param array<int, int> $ids */
    private function finalizableBudgets(Request $request, array $ids): \Illuminate\Support\Collection
    {
        return BudgetRequest::query()
            ->whereKey($ids)
            ->awaitingFinance()
            ->with(['campaign', 'maker', 'purchaseRequest', 'lines.campaign', 'lines.purchaseOrder.supplier', 'lines.purchaseOrder.purchaseRequest'])
            ->orderBy('id')
            ->get()
            ->filter(fn (BudgetRequest $budget) => $budget->purchaseRequest
                && $request->user()->can('reviewBudgetFinal', $budget->purchaseRequest))
            ->values();
    }

    /** @param array{remarks?: ?string, signature_name: string, signature_data: string} $validated */
    private function finalizeBudget(Request $request, PurchaseRequest $purchaseRequest, PurchaseRequestWorkflow $workflow, array $validated): void
    {
        $lockedRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
        $this->authorize('reviewBudgetFinal', $lockedRequest);

        $budget = $lockedRequest->budgetRequest()->firstOrFail();
        $groupRequests = $this->groupRequestsForUpdate($budget);
        $pendingStatuses = [
            PurchaseRequestStatus::ForBudgetMaker,
            PurchaseRequestStatus::ForBudgetChecker,
            PurchaseRequestStatus::ForBudgetProceeder,
            PurchaseRequestStatus::AwaitingPresidentFinalApproval,
        ];
        if ($groupRequests->isEmpty()
            || $groupRequests->contains(fn (PurchaseRequest $item) => ! in_array($item->status, $pendingStatuses, true))) {
            throw ValidationException::withMessages(['status' => 'One or more requests in this Budget Request are not waiting for the Final budget.']);
        }

        $budget->update([
            'budget_request_number' => $budget->budget_request_number ?? BudgetRequest::nextNumber(),
            'final_approval_by' => $request->user()->id,
            'final_approval_at' => now(),
            'final_approval_status' => 'APPROVED',
            'status' => 'APPROVED',
            'rejection_reason' => null,
        ]);

        foreach ($groupRequests as $groupRequest) {
            $this->authorize('reviewBudgetFinal', $groupRequest);
            $workflow->transition(
                $groupRequest,
                PurchaseRequestStatus::ForPayment,
                $request->user(),
                'PURCHASING_BUDGET_APPROVED',
                $validated['remarks'] ?? 'Final budget done; BR '.$budget->budget_request_number.' issued for payment.',
                'PURCHASING_BUDGET_FINAL_APPROVAL',
                ['budget_request_id' => $budget->id, 'budget_request_number' => $budget->budget_request_number, 'batch_size' => $groupRequests->count()],
                $validated['signature_name'],
                $validated['signature_data'],
            );
        }
    }

    /** Inside the dashboard action modal, close the modal instead of loading the next page in it. */
    private function finished(Request $request, RedirectResponse $redirect, string $message): RedirectResponse
    {
        return ($request->boolean('_embedded') ? redirect()->away(route('workflow.done', [], false)) : $redirect)
            ->with('success', $message);
    }


    /** @return array<string, array<int, mixed>> */
    private static function budgetInformationRules(): array
    {
        return [
            'account_type' => ['nullable', Rule::in(['ACA', 'OTHER_PARTY', 'OWN_ACCOUNT'])],
            'transaction_type' => ['nullable', Rule::in(['ONLINE', 'CASH', 'CHECK'])],
            'fund_source' => ['nullable', Rule::in(['MAIN_FUND', 'CAMPAIGN_FUND', 'OTHER'])],
            'main_fund' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function validateSelection(PurchaseRequest $purchaseRequest): void
    {
        if ($purchaseRequest->status !== PurchaseRequestStatus::ForBudgetProcessing) {
            throw ValidationException::withMessages([
                'purchase_request_id' => "{$purchaseRequest->request_number} is no longer awaiting Budget Request preparation.",
            ]);
        }

        if (! $purchaseRequest->purchaseOrder && ! $purchaseRequest->skippedPurchaseOrder()) {
            throw ValidationException::withMessages([
                'purchase_request_id' => "{$purchaseRequest->request_number} does not have a Purchase Order.",
            ]);
        }

        if ($purchaseRequest->purchaseOrder && ! $purchaseRequest->purchaseOrder->isApproved()) {
            throw ValidationException::withMessages([
                'purchase_request_id' => "{$purchaseRequest->purchaseOrder->po_number} has not completed final PO approval. Budget Request cannot be created because the Purchase Order has not yet completed approval.",
            ]);
        }

        $reference = $purchaseRequest->purchaseOrder?->po_number ?? $purchaseRequest->request_number;
        if ($purchaseRequest->budgetRequest()->exists()) {
            throw ValidationException::withMessages([
                'purchase_request_id' => "{$reference} is already included in a Budget Request.",
            ]);
        }

        if (BudgetRequest::query()
            ->where('purchase_request_id', $purchaseRequest->id)
            ->when($purchaseRequest->purchaseOrder, fn ($query, $order) => $query->orWhere('purchase_order_id', $order->id))
            ->exists()) {
            throw ValidationException::withMessages([
                'purchase_request_id' => "{$reference} is already included in a Budget Request.",
            ]);
        }

        $this->authorize('prepareBudgetRequest', $purchaseRequest);
    }

    private function groupRequestsForUpdate(BudgetRequest $budget): Collection
    {
        $requestIds = $budget->allocations()->pluck('purchase_request_id');

        return PurchaseRequest::query()
            ->whereIn('id', $requestIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
