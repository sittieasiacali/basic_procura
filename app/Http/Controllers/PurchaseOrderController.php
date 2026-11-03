<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseOrderApprovalStage;
use App\Enums\PurchaseRequestStatus;
use App\Enums\RoleSlug;
use App\Models\PoRoleAssignment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SupplierQuotation;
use App\Services\WorkflowEmailNotifier;
use App\Support\PurchaseOrderDocumentData;
use App\Support\PurchaseOrderLinePricing;
use App\Support\PurchaseOrderNumber;
use App\Support\WorkflowSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function __construct(private WorkflowEmailNotifier $workflowEmails) {}

    public function preview(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->load(['purchaseRequest.requestor', 'purchaseRequest.teamLeader', 'purchaseRequest.quotations.supplier', 'purchaseRequest.quotations.item', 'supplier', 'budgetCampaign', 'items.requestItem', 'budgetRequest', 'approvals.assignedUser', 'approvals.actor']);
        $this->authorize('view', $purchaseOrder);
        $document = PurchaseOrderDocumentData::from($purchaseOrder->purchaseRequest);

        return response()->json(['po_number' => $purchaseOrder->po_number, 'status' => $purchaseOrder->status, 'is_processed' => $purchaseOrder->isProcessed(), 'can_print' => $purchaseOrder->isApproved(), 'can_edit' => request()->user()->can('update', $purchaseOrder), 'template' => 'official-purchase-order', 'template_error' => null, 'account_classification' => $document['accountClassification'], 'campaign_bank_account' => $document['campaignBankAccount'], 'html' => view('purchase-requests.partials.purchase-order-official', ['purchaseOrderDocument' => $document])->render(), 'details_html' => view('purchase-requests.partials.purchase-order-details-template', ['purchaseOrderDocument' => $document])->render(), 'account_type_html' => view('purchase-requests.partials.purchase-order-account-type', ['purchaseOrderDocument' => $document])->render(), 'campaign_bank_account_html' => view('purchase-requests.partials.purchase-order-campaign-bank-account', ['purchaseOrderDocument' => $document])->render(), 'can_edit_task' => $this->editableTaskStages(request()->user(), $purchaseOrder) !== [], 'task_edit_html' => $this->taskEditHtml($purchaseOrder)]);
    }

    /** Lets each completed PO signer revise only their own task, with every change logged. */
    public function updateTask(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse|JsonResponse
    {
        $order = $purchaseRequest->purchaseOrder()->with('approvals')->firstOrFail();
        $this->authorize('view', $order);
        $stages = $this->editableTaskStages($request->user(), $order);
        abort_if($stages === [], 403, 'You have no completed Purchase Order task that can still be edited.');

        $canEditDetails = in_array(PurchaseOrderApprovalStage::PreparedBy, $stages, true);
        $validated = $request->validate([
            'stage_remarks' => ['sometimes', 'array'],
            'stage_remarks.*' => ['nullable', 'string', 'max:2000'],
            ...($canEditDetails ? [
                'account_number' => ['required', 'string', 'max:255'],
                'payment_terms' => ['required', 'string', 'max:255'],
                'expected_delivery_date' => ['required', 'date'],
                'payment_details' => ['required', 'string', 'max:2000'],
                'remarks' => ['nullable', 'string', 'max:2000'],
            ] : []),
        ]);
        $editableValues = array_map(fn (PurchaseOrderApprovalStage $stage) => $stage->value, $stages);
        foreach (array_keys($validated['stage_remarks'] ?? []) as $stageValue) {
            abort_unless(in_array($stageValue, $editableValues, true), 403, 'You can only edit your own Purchase Order task.');
        }

        $changes = DB::transaction(function () use ($request, $purchaseRequest, $validated, $canEditDetails): array {
            $order = $purchaseRequest->purchaseOrder()->lockForUpdate()->firstOrFail();
            $order->load('approvals');
            $stages = $this->editableTaskStages($request->user(), $order);
            abort_if($stages === [], 403, 'This Purchase Order can no longer be edited.');
            $changes = [];
            $normalize = function ($value): ?string {
                $value = trim((string) $value);

                return $value === '' ? null : $value;
            };

            if ($canEditDetails && in_array(PurchaseOrderApprovalStage::PreparedBy, $stages, true)) {
                $fields = [
                    'account_number' => 'Payment account number',
                    'payment_terms' => 'Payment terms',
                    'expected_delivery_date' => 'Expected delivery',
                    'payment_details' => 'Payment details',
                    'remarks' => 'Remarks',
                ];
                $updates = [];
                foreach ($fields as $field => $label) {
                    $from = $field === 'expected_delivery_date' ? $order->expected_delivery_date?->toDateString() : $normalize($order->{$field});
                    $to = $normalize($validated[$field] ?? null);
                    if ($from !== $to) {
                        $changes[] = ['stage' => PurchaseOrderApprovalStage::PreparedBy->value, 'field' => $label, 'from' => $from, 'to' => $to];
                        $updates[$field] = $to;
                    }
                }
                if ($updates !== []) {
                    $order->update($updates);
                }
                if (array_key_exists('remarks', $updates)) {
                    $order->approvals()->where('stage', PurchaseOrderApprovalStage::PreparedBy->value)->update(['remarks' => $updates['remarks'], 'updated_at' => now()]);
                }
            }

            foreach ($stages as $stage) {
                if ($stage === PurchaseOrderApprovalStage::PreparedBy || ! array_key_exists($stage->value, $validated['stage_remarks'] ?? [])) {
                    continue;
                }
                $approval = $order->approvalFor($stage);
                $from = $normalize($approval->remarks);
                $to = $normalize($validated['stage_remarks'][$stage->value]);
                if ($from === $to) {
                    continue;
                }
                $order->approvals()->whereKey($approval->id)->update(['remarks' => $to, 'updated_at' => now()]);
                $legacyColumn = match ($stage) {
                    PurchaseOrderApprovalStage::CheckedBy => 'checker_remarks',
                    PurchaseOrderApprovalStage::NotedBy => 'acknowledgement_remarks',
                    default => $approval->status === 'REJECTED' ? 'rejection_reason' : 'approval_remarks',
                };
                $order->update([$legacyColumn => $to]);
                $changes[] = ['stage' => $stage->value, 'field' => $this->stageLabel($stage).' remarks', 'from' => $from, 'to' => $to];
            }

            if ($changes !== []) {
                $purchaseRequest->auditLogs()->create([
                    'user_id' => $request->user()->id,
                    'action' => 'PO_TASK_EDITED',
                    'previous_status' => $purchaseRequest->status->value,
                    'new_status' => $purchaseRequest->status->value,
                    'remarks' => 'Purchase Order task edited.',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'metadata' => ['purchase_order_id' => $order->id, 'changes' => $changes],
                ]);
            }

            return $changes;
        });

        $message = $changes === [] ? 'No changes to save.' : 'Purchase Order task updated.';
        if ($request->expectsJson()) {
            $order = $purchaseRequest->purchaseOrder()->firstOrFail();

            return response()->json(['message' => $message, 'task_edit_html' => $this->taskEditHtml($order)]);
        }

        return back()->with('success', $message);
    }

    /** @return list<PurchaseOrderApprovalStage> */
    private function editableTaskStages($user, PurchaseOrder $order): array
    {
        return array_values(array_filter(
            PurchaseOrderApprovalStage::cases(),
            fn (PurchaseOrderApprovalStage $stage) => $user->can('editTask', [$order, $stage]),
        ));
    }

    private function taskEditHtml(PurchaseOrder $order): string
    {
        $order->load(['approvals.assignedUser', 'approvals.actor', 'purchaseRequest']);
        $history = $order->purchaseRequest->auditLogs()
            ->where('action', 'PO_TASK_EDITED')
            ->with('user:id,name')
            ->latest('created_at')
            ->latest('id')
            ->get()
            ->filter(fn ($log) => (int) ($log->metadata['purchase_order_id'] ?? 0) === (int) $order->id)
            ->values();

        return view('purchase-orders.partials.task-edit', [
            'purchaseOrder' => $order,
            'editableStages' => $this->editableTaskStages(request()->user(), $order),
            'history' => $history,
            'stageLabels' => collect(PurchaseOrderApprovalStage::cases())->mapWithKeys(fn ($stage) => [$stage->value => $this->stageLabel($stage)])->all(),
        ])->render();
    }

    public function document(Request $request, PurchaseRequest $purchaseRequest): View
    {
        abort_unless($purchaseRequest->purchaseOrder()->exists(), 404);
        $this->authorize('view', $purchaseRequest->purchaseOrder);
        $purchaseRequest->load(['requestor', 'quotations.supplier', 'quotations.creator', 'quotations.approver', 'quotations.item', 'purchaseOrder.supplier', 'purchaseOrder.budgetCampaign', 'purchaseOrder.items.requestItem', 'purchaseOrder.approvals.assignedUser', 'purchaseOrder.approvals.actor']);
        return view('purchase-orders.document', ['purchaseRequest' => $purchaseRequest, 'purchaseOrderDocument' => PurchaseOrderDocumentData::from($purchaseRequest), 'canPrint' => $purchaseRequest->purchaseOrder->isApproved(), 'mode' => $request->string('mode')->toString()]);
    }

    public function store(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('prepare', PurchaseOrder::class);
        $validated = $request->validate([
            'expected_delivery_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'payable_on' => ['required', 'date'],
            'payment_details' => ['required', 'string', 'max:2000'],
            'account_number' => ['required', 'string', 'max:255'],
            'account_name' => ['required', 'string', 'max:255'],
            'payment_terms' => ['required', 'string', 'max:255'],
            'po_items' => ['sometimes', 'array'],
            'po_items.*.quantity' => ['required_with:po_items', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'po_items.*.unit' => ['required_with:po_items', 'string', 'max:50'],
            ...WorkflowSignature::rules(),
        ]);
        WorkflowSignature::assertValid($request, $validated);
        $created = false;
        $order = DB::transaction(function () use ($request, $purchaseRequest, $validated, &$created) {
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            $this->authorize('prepare', PurchaseOrder::class);
            if (! $locked->eligibleForPurchaseOrderPreparation()->whereKey($locked->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'The request must have an approved supplier and be ready for PO preparation.']);
            }
            $approvedQuotations = SupplierQuotation::deduplicateForReview(
                $locked->quotations()->where('supplier_id', $locked->selected_supplier_id)->where('is_approved', true)->get()
            );
            $accountName = trim((string) ($validated['account_name'] ?? $locked->selectedSupplier?->name));
            $paymentTerms = trim((string) ($validated['payment_terms'] ?? $approvedQuotations->pluck('delivery_terms')->filter()->first()));
            if ($accountName === '' || $paymentTerms === '') {
                throw ValidationException::withMessages(array_filter([
                    'account_name' => $accountName === '' ? 'A payee account name is required.' : null,
                    'payment_terms' => $paymentTerms === '' ? 'Payment terms are required when the approved quotation has none.' : null,
                ]));
            }
            $approvedSubtotal = round((float) $approvedQuotations->sum(fn (SupplierQuotation $quotation) => $quotation->vatBreakdown()['base']), 2);
            $approvedVat = round((float) $approvedQuotations->sum(fn (SupplierQuotation $quotation) => $quotation->vatBreakdown()['vat']), 2);
            $approvedTotal = round((float) $approvedQuotations->sum(fn (SupplierQuotation $quotation) => $quotation->vatBreakdown()['total']), 2);
            $items = $locked->items;
            $needsLineDetails = $items->contains(fn ($item) => $item->quantity === null || blank($item->unit) || $item->estimated_unit_price === null);
            $poLines = [];
            if ($needsLineDetails) {
                $submittedLines = $validated['po_items'] ?? [];
                $requestItemIds = $items->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
                $submittedItemIds = collect(array_keys($submittedLines))->map(fn ($id) => (string) $id)->sort()->values()->all();
                if ($requestItemIds !== $submittedItemIds) throw ValidationException::withMessages(['po_items' => 'Enter purchase order details for every requested item.']);
            }
            $lineAmounts = PurchaseOrderLinePricing::amounts($items, $approvedQuotations);
            foreach ($items as $item) {
                $submitted = $needsLineDetails ? $submittedLines[$item->id] : [];
                $quantity = (float) ($submitted['quantity'] ?? $item->quantity);
                $unit = $submitted['unit'] ?? $item->unit;
                if ($quantity <= 0 || blank($unit)) {
                    throw ValidationException::withMessages(['po_items' => 'Enter a quantity and unit for every requested item.']);
                }
                $amount = $lineAmounts[$item->id] ?? 0;
                $poLines[$item->id] = [
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'unit_price' => PurchaseOrderLinePricing::unitPrice($amount, $quantity),
                    'total_price' => $amount,
                ];
            }
            if ($locked->purchaseOrder()->exists()) return $locked->purchaseOrder;
            $assignments = PoRoleAssignment::query()->pluck('user_id', 'stage');
            foreach (PurchaseOrderApprovalStage::cases() as $stage) if (! $assignments->has($stage->value)) throw ValidationException::withMessages(['po_assignments' => 'Configure all four PO-specific assignments before preparing a Purchase Order.']);
            abort_unless(
                $request->user()->hasRole(RoleSlug::PurchasingTeam->value)
                    || (int) $assignments[PurchaseOrderApprovalStage::PreparedBy->value] === (int) $request->user()->id,
                403,
                'Only the assigned PO Preparer may create and sign this Purchase Order.',
            );
            $campaign = $locked->teamLeader?->campaign ?? $locked->requestor?->campaign;
            if (! $campaign?->is_active) throw ValidationException::withMessages(['campaign' => 'An active Campaign Account is required.']);
            $order = $locked->purchaseOrder()->create(['po_number' => PurchaseOrderNumber::reserve(), 'supplier_id' => $locked->selected_supplier_id, 'created_by' => $request->user()->id, 'issued_at' => now()->toDateString(), 'expected_delivery_date' => $validated['expected_delivery_date'] ?? $locked->required_date?->toDateString(), 'status' => 'FOR_CHECKING', 'subtotal' => $approvedSubtotal, 'tax_amount' => $approvedVat, 'total_amount' => $approvedTotal, 'remarks' => $validated['remarks'] ?? null, 'budget_campaign_id' => $campaign->id, 'budget_campaign_account_number' => $campaign->account_number, 'budget_payable_on' => $validated['payable_on'] ?? null, 'budget_source_bank_account' => $campaign->account_number, 'account_number' => trim($validated['account_number']), 'account_name' => $accountName, 'payment_terms' => $paymentTerms, 'payment_details' => $validated['payment_details'] ?? null, 'requested_by' => $locked->teamLeader?->name ?? $locked->requestor?->name]);
            $preparedAt = now();
            foreach (PurchaseOrderApprovalStage::cases() as $stage) {
                $isPreparedStage = $stage === PurchaseOrderApprovalStage::PreparedBy;
                $order->approvals()->create([
                    'stage' => $stage,
                    'assigned_user_id' => $isPreparedStage ? $request->user()->id : $assignments[$stage->value],
                    'acted_by' => $isPreparedStage ? $request->user()->id : null,
                    'status' => $isPreparedStage ? 'APPROVED' : 'PENDING',
                    'decision' => $isPreparedStage ? 'APPROVED' : null,
                    'remarks' => $isPreparedStage ? ($validated['remarks'] ?? null) : null,
                    'signature_name' => $isPreparedStage ? $validated['signature_name'] : null,
                    'signature_data' => $isPreparedStage ? $validated['signature_data'] : null,
                    'signed_at' => $isPreparedStage ? $preparedAt : null,
                    'acted_at' => $isPreparedStage ? $preparedAt : null,
                ]);
            }
            foreach ($items as $item) { $order->items()->create(['purchase_request_item_id' => $item->id, 'description' => $item->description, ...$poLines[$item->id], 'specifications' => $item->brand_specifications]); }
            $locked->auditLogs()->create([
                'user_id' => $request->user()->id,
                'action' => 'PURCHASE_ORDER_CREATED',
                'previous_status' => $locked->status->value,
                'new_status' => $locked->status->value,
                'remarks' => 'Purchase order created and submitted for checking.',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => ['role' => $request->user()->activeRole()?->slug, 'purchase_order_id' => $order->id, 'source' => 'approved_quotation'],
            ]);
            $this->workflowEmails->purchaseOrderMoved($order, $request->user());
            $created = true; return $order;
        });
        $redirect = route('purchase-requests.show', ['purchaseRequest' => $purchaseRequest, 'from_stage' => 'budget-request']);

        return redirect()->to($redirect);
    }

    /** The PO step is optional: send the request to Budget priced from its approved quotation. */
    public function skip(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('prepare', PurchaseOrder::class);
        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);

        DB::transaction(function () use ($request, $purchaseRequest, $validated): void {
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            if (! $locked->eligibleForPurchaseOrderPreparation()->whereKey($locked->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'Only a request waiting for its Purchase Order can skip it.']);
            }

            $locked->forceFill(['purchase_order_skipped_at' => now(), 'purchase_order_skipped_by' => $request->user()->id])->save();
            $locked->auditLogs()->create([
                'user_id' => $request->user()->id,
                'action' => 'PURCHASE_ORDER_SKIPPED',
                'previous_status' => $locked->status->value,
                'new_status' => $locked->status->value,
                'remarks' => $validated['remarks'] ?? 'Proceeded to Budget Request without a Purchase Order.',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => ['role' => $request->user()->activeRole()?->slug],
            ]);
        });

        return redirect()->route('purchase-requests.show', ['purchaseRequest' => $purchaseRequest, 'from_stage' => 'budget-request'])
            ->with('success', 'Purchase Order skipped. The request is now ready for its Budget Request.');
    }

    public function update(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $validated = $request->validate(['expected_delivery_date' => ['required', 'date'], 'remarks' => ['nullable', 'string', 'max:2000'], 'payable_on' => ['required', 'date'], 'payment_details' => ['required', 'string', 'max:2000'], 'account_number' => ['required', 'string', 'max:255'], 'account_name' => ['required', 'string', 'max:255'], 'payment_terms' => ['required', 'string', 'max:255'], 'po_items' => ['sometimes', 'array'], 'po_items.*.quantity' => ['required_with:po_items', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'], 'po_items.*.unit' => ['required_with:po_items', 'string', 'max:50'], 'po_items.*.unit_price' => ['required_with:po_items', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99']]);
        DB::transaction(function () use ($request, $purchaseRequest, $validated) { $order = $purchaseRequest->purchaseOrder()->lockForUpdate()->firstOrFail(); $order->load('approvals'); $this->authorize('update', $order); $order->update(['expected_delivery_date' => $validated['expected_delivery_date'] ?? $order->expected_delivery_date?->toDateString(), 'remarks' => $validated['remarks'] ?? $order->remarks, 'budget_payable_on' => $validated['payable_on'] ?? $order->budget_payable_on?->toDateString(), 'payment_details' => $validated['payment_details'] ?? $order->payment_details, 'account_number' => trim($validated['account_number']), 'account_name' => trim((string) ($validated['account_name'] ?? $order->account_name)), 'payment_terms' => trim((string) ($validated['payment_terms'] ?? $order->payment_terms))]); });
        return back()->with('success', 'Purchase Order details updated.');
    }

    public function moveToChecker(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->signWithActionIfProvided($request, $purchaseRequest, PurchaseOrderApprovalStage::PreparedBy);
        $order = $purchaseRequest->purchaseOrder()->with('approvals')->firstOrFail();
        $this->authorize('moveToChecker', $order);

        DB::transaction(function () use ($request, $purchaseRequest): void {
            $order = $purchaseRequest->purchaseOrder()->lockForUpdate()->firstOrFail();
            $order->load('approvals');
            $this->authorize('moveToChecker', $order);

            $order->approvals()
                ->where('stage', PurchaseOrderApprovalStage::PreparedBy->value)
                ->update(['acted_at' => now(), 'updated_at' => now()]);
            $order->load('approvals');

            $checked = $order->approvalFor(PurchaseOrderApprovalStage::CheckedBy);
            $noted = $order->approvalFor(PurchaseOrderApprovalStage::NotedBy);
            $checkedComplete = $checked?->status === 'APPROVED'
                && ($checked?->hasValidSignature() ?? false)
                && $checked?->acted_at !== null
                && $checked?->signed_at !== null
                && $checked->acted_at->greaterThanOrEqualTo($checked->signed_at);
            $notedComplete = $noted?->status === 'APPROVED'
                && ($noted?->hasValidSignature() ?? false)
                && $noted?->acted_at !== null
                && $noted?->signed_at !== null
                && $noted->acted_at->greaterThanOrEqualTo($noted->signed_at);
            $nextStatus = $checkedComplete
                ? ($notedComplete ? 'FOR_APPROVAL' : 'FOR_ACKNOWLEDGEMENT')
                : 'FOR_CHECKING';
            $previousStatus = $order->status;
            if ($previousStatus !== $nextStatus) {
                $order->update(['status' => $nextStatus]);
            }

            $purchaseRequest->auditLogs()->create([
                'user_id' => $request->user()->id,
                'action' => 'PURCHASE_ORDER_MOVED_TO_CHECKER',
                'previous_status' => $previousStatus,
                'new_status' => $nextStatus,
                'remarks' => 'Prepared By signature confirmed and Purchase Order handed to the assigned Checker.',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'purchase_order_id' => $order->id,
                    'stage' => PurchaseOrderApprovalStage::PreparedBy->value,
                ],
            ]);
            $this->workflowEmails->purchaseOrderMoved($order, $request->user());
        });

        return back();
    }
    public function check(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->transition($request, $purchaseRequest, PurchaseOrderApprovalStage::CheckedBy, 'check', 'FOR_ACKNOWLEDGEMENT', ['checked_by' => $request->user()->id, 'checked_at' => now(), 'checker_remarks' => $request->input('remarks')]);

        return back();
    }

    public function acknowledge(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->transition($request, $purchaseRequest, PurchaseOrderApprovalStage::NotedBy, 'note', 'FOR_APPROVAL', ['acknowledged_by' => $request->user()->id, 'acknowledged_at' => now(), 'acknowledgement_remarks' => $request->input('remarks')]);

        return back();
    }

public function approve(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse|JsonResponse
    {
        $order = $purchaseRequest->purchaseOrder()->with('approvals')->firstOrFail();
        $this->authorize('approve', $order);
        $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);
        $this->signWithActionIfProvided($request, $purchaseRequest, PurchaseOrderApprovalStage::ApprovedBy);
        $this->enforceSignaturesThrough($request, $purchaseRequest, PurchaseOrderApprovalStage::ApprovedBy);
        $approve = $request->input('decision', 'APPROVE') === 'APPROVE';

        DB::transaction(function () use ($request, $purchaseRequest, $approve) {
            $order = $purchaseRequest->purchaseOrder()->lockForUpdate()->firstOrFail();
            $order->load('approvals');
            $this->authorize('approve', $order);
            $this->assertSignaturesThrough($order, PurchaseOrderApprovalStage::ApprovedBy);
            $order->update($approve
                ? ['status' => 'APPROVED', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'approval_remarks' => $request->input('remarks'), 'rejection_reason' => null]
                : ['status' => 'REJECTED', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'rejection_reason' => $request->input('remarks')]);
            $this->record($order, PurchaseOrderApprovalStage::ApprovedBy, $request, $approve ? 'APPROVED' : 'REJECTED');
        });

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => $approve ? 'Purchase Order approved.' : 'Purchase Order rejected.',
            ]);
        }

        return back();
    }

    public function prepareSignature(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        return $this->signature($request, $purchaseRequest, PurchaseOrderApprovalStage::PreparedBy->value);
    }

public function signature(Request $request, PurchaseRequest $purchaseRequest, string $stage): RedirectResponse
    {
        $approvalStage = PurchaseOrderApprovalStage::tryFrom($stage);
        abort_unless($approvalStage, 404);

        $order = $purchaseRequest->purchaseOrder()->with('approvals')->firstOrFail();
        $this->authorize('sign', [$order, $approvalStage]);
        $this->persistSignature($request, $purchaseRequest, $approvalStage);

        // After saving the signature, enforce that all prior stages are complete
        // and advance the PO status if appropriate
        $this->enforceSignaturesThrough($request, $purchaseRequest, $approvalStage);

        return back()->with('success', $this->stageLabel($approvalStage).' signature saved.');
    }

    /**
     * Saves the signature drawn in a workflow action form (Move to Checker, Check,
     * Note, Approve) so the assignee does not need a separate "Save signature" step.
     */
    private function signWithActionIfProvided(Request $request, PurchaseRequest $purchaseRequest, PurchaseOrderApprovalStage $stage): void
    {
        if (! $request->filled('signature_data')) {
            return;
        }

        $order = $purchaseRequest->purchaseOrder()->with('approvals')->firstOrFail();
        if ($order->approvalFor($stage)?->hasValidSignature() ?? false) {
            return;
        }

        $this->authorize('sign', [$order, $stage]);
        $this->persistSignature($request, $purchaseRequest, $stage);
    }

    private function persistSignature(Request $request, PurchaseRequest $purchaseRequest, PurchaseOrderApprovalStage $approvalStage): void
    {
        $validated = $request->validate(WorkflowSignature::rules());
        WorkflowSignature::assertValid($request, $validated);

        DB::transaction(function () use ($request, $purchaseRequest, $approvalStage, $validated): void {
            $order = $purchaseRequest->purchaseOrder()->lockForUpdate()->firstOrFail();
            $order->load('approvals');
            $this->authorize('sign', [$order, $approvalStage]);
            $this->returnOrderToSignatureStage($request, $order, $approvalStage);

            $updated = $order->approvals()
                ->where('stage', $approvalStage->value)
                ->update([
                    'signature_name' => $validated['signature_name'],
                    'signature_data' => $validated['signature_data'],
                    'signed_at' => now(),
                    'acted_by' => $request->user()->id,
                    'acted_at' => null,
                    'updated_at' => now(),
                ]);

            abort_unless($updated === 1, 409, 'This workflow stage has already been signed.');
        });
    }

    private function transition(Request $request, PurchaseRequest $purchaseRequest, PurchaseOrderApprovalStage $stage, string $ability, string $next, array $legacy): void
    {
        $order = $purchaseRequest->purchaseOrder()->with('approvals')->firstOrFail();
        $this->authorize($ability, $order);
        $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);
        $this->signWithActionIfProvided($request, $purchaseRequest, $stage);
        $this->enforceSignaturesThrough($request, $purchaseRequest, $stage);

        DB::transaction(function () use ($request, $purchaseRequest, $stage, $ability, $next, $legacy) {
            $order = $purchaseRequest->purchaseOrder()->lockForUpdate()->firstOrFail();
            $order->load('approvals');
            $this->authorize($ability, $order);
            $this->assertSignaturesThrough($order, $stage);
            $order->update(['status' => $next, ...$legacy]);
            $this->record($order, $stage, $request, 'APPROVED');
        });
    }

    private function record(PurchaseOrder $order, PurchaseOrderApprovalStage $stage, Request $request, string $decision): void
    {
        $order->approvals()->where('stage', $stage->value)->update([
            'acted_by' => $request->user()->id,
            'status' => $decision,
            'decision' => $decision,
            'remarks' => $request->input('remarks'),
            'acted_at' => now(),
            'updated_at' => now(),
        ]);
        $this->workflowEmails->purchaseOrderMoved($order, $request->user());
    }

    private function enforceSignaturesThrough(Request $request, PurchaseRequest $purchaseRequest, PurchaseOrderApprovalStage $through): void
    {
        $missing = DB::transaction(function () use ($request, $purchaseRequest, $through): ?PurchaseOrderApprovalStage {
            $order = $purchaseRequest->purchaseOrder()->lockForUpdate()->firstOrFail();
            $order->load('approvals');
            $missing = $this->firstMissingSignatureThrough($order, $through);

            if ($missing !== null) {
                $this->returnOrderToSignatureStage($request, $order, $missing);
            }

            return $missing;
        });

        if ($missing !== null) {
            throw ValidationException::withMessages([
                'signature_data' => 'The '.$this->stageLabel($missing).' signature is required. The Purchase Order has been returned to '.$this->stageLabel($missing).' so the assigned user can sign it.',
            ]);
        }
    }

    private function assertSignaturesThrough(PurchaseOrder $order, PurchaseOrderApprovalStage $through): void
    {
        $missing = $this->firstMissingSignatureThrough($order, $through);

        if ($missing !== null) {
            throw ValidationException::withMessages([
                'signature_data' => 'The '.$this->stageLabel($missing).' signature is required before this Purchase Order can proceed.',
            ]);
        }
    }

    private function firstMissingSignatureThrough(PurchaseOrder $order, PurchaseOrderApprovalStage $through): ?PurchaseOrderApprovalStage
    {
        foreach (PurchaseOrderApprovalStage::cases() as $stage) {
            if (! ($order->approvalFor($stage)?->hasValidSignature() ?? false)) {
                return $stage;
            }

            if ($stage === $through) {
                break;
            }
        }

        return null;
    }

    private function returnOrderToSignatureStage(Request $request, PurchaseOrder $order, PurchaseOrderApprovalStage $stage): void
    {
        $targetStatus = match ($stage) {
            PurchaseOrderApprovalStage::PreparedBy, PurchaseOrderApprovalStage::CheckedBy => 'FOR_CHECKING',
            PurchaseOrderApprovalStage::NotedBy => 'FOR_ACKNOWLEDGEMENT',
            PurchaseOrderApprovalStage::ApprovedBy => 'FOR_APPROVAL',
        };

        if ($order->status === $targetStatus) {
            return;
        }

        $previousStatus = $order->status;
        $order->update(['status' => $targetStatus]);
        $order->purchaseRequest->auditLogs()->create([
            'user_id' => $request->user()->id,
            'action' => 'PO_SIGNATURE_REQUIRED',
            'previous_status' => $previousStatus,
            'new_status' => $targetStatus,
            'remarks' => 'Purchase Order returned for the missing '.$this->stageLabel($stage).' signature.',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'purchase_order_id' => $order->id,
                'missing_signature_stage' => $stage->value,
            ],
        ]);
        $this->workflowEmails->purchaseOrderMoved($order, $request->user(), $stage);
    }

    private function stageLabel(PurchaseOrderApprovalStage $stage): string
    {
        return match ($stage) {
            PurchaseOrderApprovalStage::PreparedBy => 'Prepared By',
            PurchaseOrderApprovalStage::CheckedBy => 'Checked By',
            PurchaseOrderApprovalStage::NotedBy => 'Noted By',
            PurchaseOrderApprovalStage::ApprovedBy => 'Approved By',
        };
    }
}
