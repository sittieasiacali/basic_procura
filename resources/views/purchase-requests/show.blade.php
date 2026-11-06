@extends('layouts.home')

@section('title', $purchaseRequest->request_number)

@section('content')
    @php
        $isPreview = request()->boolean('preview');
        $pendingTeamClassification = $purchaseRequest->assigned_user_id === null
            && in_array($purchaseRequest->status, [
                \App\Enums\PurchaseRequestStatus::ForTeamScreening,
                \App\Enums\PurchaseRequestStatus::ForItScreening,
                \App\Enums\PurchaseRequestStatus::ForPremisesScreening,
            ], true);
        $requestTypeLabel = $pendingTeamClassification ? 'Pending IT / Premises classification' : match($purchaseRequest->request_type) {
            'IT' => 'IT',
            'PREMISES' => 'Premises',
            'OTHER' => 'Other / Procurement Review',
            'UNCLASSIFIED' => 'Pending IT / Premises classification',
            default => $purchaseRequest->request_type,
        };
        $latestReturn = $purchaseRequest->auditLogs->where('action', 'OM_RETURNED')->sortByDesc('created_at')->first();
        $hideQuotationSummary = auth()->user()->hasAnyRole([
            \App\Enums\RoleSlug::Om->value,
            \App\Enums\RoleSlug::OmSmtsApprover->value,
        ]);
        $purchaseOrderWorkflow = null;

        if ($purchaseRequest->purchaseOrder?->status === 'FOR_CHECKING') {
            $purchaseOrderWorkflow = [
                'stage' => 'Purchase Order Checking',
                'action' => 'PO Checker: check the Purchase Order.',
                'title' => 'Check Purchase Order',
                'role' => 'PO Checker',
            ];
        } elseif ($purchaseRequest->purchaseOrder?->status === 'FOR_ACKNOWLEDGEMENT') {
            $purchaseOrderWorkflow = [
                'stage' => 'Purchase Order Notation',
                'action' => 'PO Noter: note the checked Purchase Order.',
                'title' => 'Note Purchase Order',
                'role' => 'PO Noter',
            ];
        } elseif ($purchaseRequest->purchaseOrder?->status === 'FOR_APPROVAL') {
            $purchaseOrderWorkflow = [
                'stage' => 'Purchase Order Approval',
                'action' => 'PO Approver: approve or reject the noted Purchase Order.',
                'title' => 'Approve Purchase Order',
                'role' => 'PO Approver',
            ];
        }
        $workflowStage = match($purchaseRequest->status) {
            \App\Enums\PurchaseRequestStatus::ForTeamScreening => 'IT / Premises Classification',
            \App\Enums\PurchaseRequestStatus::ForProcurement => 'Quotation',
            \App\Enums\PurchaseRequestStatus::ForCanvassing => 'Canvassing',
            \App\Enums\PurchaseRequestStatus::ForSupplierApproval => 'President Supplier Approval',
            \App\Enums\PurchaseRequestStatus::ForBudgetProcessing => $purchaseOrderWorkflow['stage'] ?? ($purchaseRequest->purchaseOrder ? 'Budget Request' : 'Purchase Order'),
            \App\Enums\PurchaseRequestStatus::ForBudgetMaker,
            \App\Enums\PurchaseRequestStatus::ForBudgetChecker,
            \App\Enums\PurchaseRequestStatus::ForBudgetProceeder,
            \App\Enums\PurchaseRequestStatus::AwaitingPresidentFinalApproval => 'Purchasing Final Approval',
            \App\Enums\PurchaseRequestStatus::ForPayment, \App\Enums\PurchaseRequestStatus::PaymentPending, \App\Enums\PurchaseRequestStatus::PartiallyPaid => 'Payment',
            \App\Enums\PurchaseRequestStatus::ForReceiving, \App\Enums\PurchaseRequestStatus::ForDelivery, \App\Enums\PurchaseRequestStatus::PartiallyReceived, \App\Enums\PurchaseRequestStatus::ReceivedWithInvoice => 'Receiving',
            \App\Enums\PurchaseRequestStatus::ForInventoryLogging, \App\Enums\PurchaseRequestStatus::Endorsed, \App\Enums\PurchaseRequestStatus::Completed, \App\Enums\PurchaseRequestStatus::Fulfilled => 'Inventory / Fulfilled',
            default => str($purchaseRequest->status->value)->replace('_', ' ')->title(),
        };
        $nextAction = match($purchaseRequest->status) {
            \App\Enums\PurchaseRequestStatus::ForTeamScreening => 'IT or Premises: review the request and claim it for the responsible department.',
            \App\Enums\PurchaseRequestStatus::ForProcurement => 'IT / Premises: record quotations before handing the request to Canvassing.',
            \App\Enums\PurchaseRequestStatus::ForCanvassing => 'Purchasing: review quotations and recommend a supplier for President approval.',
            \App\Enums\PurchaseRequestStatus::ForSupplierApproval => 'President: compare quotations and approve or return the Purchasing supplier recommendation.',
            \App\Enums\PurchaseRequestStatus::ForBudgetProcessing => $purchaseOrderWorkflow['action'] ?? ($purchaseRequest->purchaseOrder ? 'Purchasing: prepare the Budget Request for final approval.' : 'Purchasing: generate the purchase order from the approved quotation.'),
            \App\Enums\PurchaseRequestStatus::ForBudgetMaker,
            \App\Enums\PurchaseRequestStatus::ForBudgetChecker,
            \App\Enums\PurchaseRequestStatus::ForBudgetProceeder,
            \App\Enums\PurchaseRequestStatus::AwaitingPresidentFinalApproval => 'Purchasing: approve for payment or reject back to canvassing.',
            \App\Enums\PurchaseRequestStatus::ForPayment => 'Finance: record Online or Cash/COD payment.',
            \App\Enums\PurchaseRequestStatus::ForReceiving => 'Inventory: record receipt and update inventory.',
            \App\Enums\PurchaseRequestStatus::Completed, \App\Enums\PurchaseRequestStatus::Fulfilled => 'No further action. The request is fulfilled.',
            default => 'See the role-specific action below.',
        };
        $validReturnStages = [
            'approval', 'it', 'premises', 'inventory', 'canvassing', 'quotation', 'supplier-approval',
            'purchase-order', 'budget-request',
            'final-approval', 'payment', 'receiving', 'fulfilled', 'done',
        ];
        $returnStage = in_array(request('from_stage'), $validReturnStages, true) ? request('from_stage') : null;
        $requestFlowUrl = route('dashboard', $returnStage ? ['workflow' => $returnStage] : []);
        $workflowProgressIndex = match($purchaseRequest->status) {
            \App\Enums\PurchaseRequestStatus::ForTeamScreening,
            \App\Enums\PurchaseRequestStatus::ForItScreening,
            \App\Enums\PurchaseRequestStatus::ForPremisesScreening,
            \App\Enums\PurchaseRequestStatus::ForInventoryRelease => 1,
            \App\Enums\PurchaseRequestStatus::ForProcurement,
            \App\Enums\PurchaseRequestStatus::ForCanvassing => 2,
            \App\Enums\PurchaseRequestStatus::ForSupplierApproval,
            \App\Enums\PurchaseRequestStatus::SupplierApproved => 3,
            \App\Enums\PurchaseRequestStatus::Approved,
            \App\Enums\PurchaseRequestStatus::PoCreated,
            \App\Enums\PurchaseRequestStatus::ForBudgetProcessing,
            \App\Enums\PurchaseRequestStatus::ForBudgetMaker,
            \App\Enums\PurchaseRequestStatus::ForBudgetChecker,
            \App\Enums\PurchaseRequestStatus::ForBudgetProceeder => 4,
            \App\Enums\PurchaseRequestStatus::AwaitingPresidentFinalApproval => 5,
            \App\Enums\PurchaseRequestStatus::ForPayment,
            \App\Enums\PurchaseRequestStatus::PaymentPending,
            \App\Enums\PurchaseRequestStatus::PartiallyPaid,
            \App\Enums\PurchaseRequestStatus::ForDelivery,
            \App\Enums\PurchaseRequestStatus::PartiallyReceived,
            \App\Enums\PurchaseRequestStatus::ReceivedWithInvoice,
            \App\Enums\PurchaseRequestStatus::FullyPaid,
            \App\Enums\PurchaseRequestStatus::ForReceiving => 6,
            \App\Enums\PurchaseRequestStatus::ForInventoryLogging,
            \App\Enums\PurchaseRequestStatus::Endorsed,
            \App\Enums\PurchaseRequestStatus::Completed,
            \App\Enums\PurchaseRequestStatus::Fulfilled => 7,
            default => 0,
        };
        $workflowProgressSteps = [
            ['label' => 'Request & Review', 'complete' => 'Completed'],
            ['label' => 'Screening', 'complete' => 'Completed'],
            ['label' => 'Procurement', 'complete' => 'Completed'],
            ['label' => 'Supplier Approval', 'complete' => 'Completed'],
            ['label' => 'PO & Budget', 'complete' => 'Completed'],
            ['label' => 'Final Approval', 'complete' => 'Completed'],
            ['label' => 'Payment & Receiving', 'complete' => 'Completed'],
            ['label' => 'Fulfilled', 'complete' => 'Completed'],
        ];
        $currentStatusLabel = in_array($purchaseRequest->status, [
            \App\Enums\PurchaseRequestStatus::ForBudgetMaker,
            \App\Enums\PurchaseRequestStatus::ForBudgetChecker,
            \App\Enums\PurchaseRequestStatus::ForBudgetProceeder,
            \App\Enums\PurchaseRequestStatus::AwaitingPresidentFinalApproval,
        ], true) ? 'Awaiting Purchasing Final Approval' : str($purchaseRequest->status->value)->replace('_', ' ')->title();
        $nextActionTitle = match($purchaseRequest->status) {
            \App\Enums\PurchaseRequestStatus::ForBudgetProcessing => $purchaseOrderWorkflow['title'] ?? ($purchaseRequest->purchaseOrder || $purchaseRequest->skippedPurchaseOrder() ? 'Create Budget Request' : 'Generate Purchase Order'),
            \App\Enums\PurchaseRequestStatus::ForBudgetMaker => 'Complete Final Approval',
            \App\Enums\PurchaseRequestStatus::ForBudgetChecker,
            \App\Enums\PurchaseRequestStatus::ForBudgetProceeder => 'Complete Final Approval',
            \App\Enums\PurchaseRequestStatus::AwaitingPresidentFinalApproval => 'Complete Final Approval',
            default => $nextAction,
        };
        $responsibleRoleLabel = match($purchaseRequest->status) {
            \App\Enums\PurchaseRequestStatus::ForTeamScreening => 'IT or Premises Screening',
            \App\Enums\PurchaseRequestStatus::ForBudgetProcessing => $purchaseOrderWorkflow['role'] ?? 'Purchasing',
            \App\Enums\PurchaseRequestStatus::ForBudgetMaker,
            \App\Enums\PurchaseRequestStatus::ForBudgetChecker,
            \App\Enums\PurchaseRequestStatus::ForBudgetProceeder,
            \App\Enums\PurchaseRequestStatus::AwaitingPresidentFinalApproval => 'Purchasing',
            default => $purchaseRequest->responsibleRole?->name ?? 'None',
        };
        $canvassingActor = $purchaseRequest->purchaseOrder ? $purchaseRequest->canvassingActor() : null;
        $allQuotations = $purchaseRequest->quotations;
        $quotations = \App\Models\SupplierQuotation::deduplicateForReview($allQuotations);
        $duplicateQuotationCount = $allQuotations->count() - $quotations->count();
        $screeningRecommendation = $purchaseRequest->auditLogs->where('action', 'SCREENING_QUOTATION_RECOMMENDED')->sortByDesc('id')->first();
        $screeningRecommendedSupplierId = $screeningRecommendation?->metadata['supplier_id'] ?? null;
        $teamCanRecommendQuotation = auth()->user()->hasAnyRole([\App\Enums\RoleSlug::ItStaff->value, \App\Enums\RoleSlug::PremisesStaff->value])
            && auth()->user()->can('recommendScreeningQuotation', $purchaseRequest);
        $purchasingCanRecommendQuotation = auth()->user()->hasAnyRole([\App\Enums\RoleSlug::CanvassingStaff->value, \App\Enums\RoleSlug::Purchasing->value, \App\Enums\RoleSlug::PurchasingTeam->value, \App\Enums\RoleSlug::ProcurementStaff->value, \App\Enums\RoleSlug::Admin->value])
            && in_array($purchaseRequest->status, [\App\Enums\PurchaseRequestStatus::ForProcurement, \App\Enums\PurchaseRequestStatus::ForCanvassing], true);
        $canDecidePresidentApproval = auth()->user()->can('reviewPresident', $purchaseRequest);
        $poFocus = ! $purchaseRequest->purchaseOrder
            && ! $purchaseRequest->skippedPurchaseOrder()
            && $purchaseRequest->status === \App\Enums\PurchaseRequestStatus::ForBudgetProcessing;
        $shouldAutoOpenPurchaseOrderPreview = ! session('workflow_submission_notice')
            && (int) session('purchase_order_created') === $purchaseRequest->purchaseOrder?->id;
        $handoffPurchaseOrder = $purchaseRequest->purchaseOrder;
        $handoffPreparedApproval = $handoffPurchaseOrder?->approvalFor(\App\Enums\PurchaseOrderApprovalStage::PreparedBy);
        $handoffCheckedApproval = $handoffPurchaseOrder?->approvalFor(\App\Enums\PurchaseOrderApprovalStage::CheckedBy);
        $showPreparedHandoffReceipt = $handoffPurchaseOrder?->status === 'FOR_CHECKING'
            && (int) $handoffPreparedApproval?->assigned_user_id === (int) auth()->id()
            && $handoffPreparedApproval?->status === 'APPROVED'
            && ($handoffPreparedApproval?->hasValidSignature() ?? false)
            && $handoffPreparedApproval?->signed_at
            && $handoffPreparedApproval?->acted_at
            && $handoffPreparedApproval->acted_at->gte($handoffPreparedApproval->signed_at);

        // Once an assigned PO signer submits their stage, the page closes to a
        // submission receipt — the same pattern as the Prepared By handoff above.
        $handoffReceipt = null;
        if ($showPreparedHandoffReceipt) {
            $handoffReceipt = [
                'title' => 'Submitted to Checker.',
                'message' => 'You submitted this Purchase Order to '.($handoffCheckedApproval?->assignedUser?->name ?? 'the assigned Checker').' on '.$handoffPreparedApproval->acted_at->format('M d, Y g:i A').'. Your preparation stage is closed and no further action is required from you unless the Purchase Order is returned.',
            ];
        } elseif ($handoffPurchaseOrder
            && ! auth()->user()->can('check', $handoffPurchaseOrder)
            && ! auth()->user()->can('note', $handoffPurchaseOrder)
            && ! auth()->user()->can('approve', $handoffPurchaseOrder)) {
            $handoffNotedApproval = $handoffPurchaseOrder->approvalFor(\App\Enums\PurchaseOrderApprovalStage::NotedBy);
            $handoffApprovedApproval = $handoffPurchaseOrder->approvalFor(\App\Enums\PurchaseOrderApprovalStage::ApprovedBy);
            $completedByMe = fn ($approval, array $statuses) => (int) $approval?->assigned_user_id === (int) auth()->id()
                && in_array($approval?->status, $statuses, true)
                && $approval?->acted_at !== null;

            if ($handoffPurchaseOrder->status === 'FOR_ACKNOWLEDGEMENT' && $completedByMe($handoffCheckedApproval, ['APPROVED'])) {
                $handoffReceipt = [
                    'title' => 'Submitted to Noter.',
                    'message' => 'You checked this Purchase Order and submitted it to '.($handoffNotedApproval?->assignedUser?->name ?? 'the assigned Noter').' on '.$handoffCheckedApproval->acted_at->format('M d, Y g:i A').'. Your checking stage is closed and no further action is required from you unless the Purchase Order is returned.',
                ];
            } elseif ($handoffPurchaseOrder->status === 'FOR_APPROVAL' && $completedByMe($handoffNotedApproval, ['APPROVED'])) {
                $handoffReceipt = [
                    'title' => 'Submitted to Approver.',
                    'message' => 'You noted this Purchase Order and submitted it to '.($handoffApprovedApproval?->assignedUser?->name ?? 'the assigned Approver').' on '.$handoffNotedApproval->acted_at->format('M d, Y g:i A').'. Your noting stage is closed and no further action is required from you unless the Purchase Order is returned.',
                ];
            } elseif (in_array($handoffPurchaseOrder->status, ['APPROVED', 'REJECTED'], true)
                && $purchaseRequest->status === \App\Enums\PurchaseRequestStatus::ForBudgetProcessing
                && ! auth()->user()->can('manageBudget', $purchaseRequest)
                && $completedByMe($handoffApprovedApproval, ['APPROVED', 'REJECTED'])) {
                $handoffReceipt = $handoffApprovedApproval->status === 'APPROVED'
                    ? [
                        'title' => 'Purchase Order approved.',
                        'message' => 'You approved this Purchase Order on '.$handoffApprovedApproval->acted_at->format('M d, Y g:i A').'. It is now released for Budget Request preparation. Your approval stage is closed and no further action is required from you.',
                    ]
                    : [
                        'title' => 'Purchase Order rejected.',
                        'message' => 'You rejected this Purchase Order on '.$handoffApprovedApproval->acted_at->format('M d, Y g:i A').'. Your approval stage is closed and no further action is required from you.',
                    ];
            }
        }
        $requestStageReceipt = $handoffReceipt === null ? \App\Support\RequestStageReceipt::for($purchaseRequest, auth()->user()) : null;
        // Screening submissions are confirmed by the popup. Keep the request
        // details visible instead of replacing them with a second confirmation.
        if ($requestStageReceipt && ! in_array($requestStageReceipt['action'], ['SUBMITTED_FOR_SCREENING', 'RESUBMITTED_FOR_SCREENING'], true)) {
            // Like the PO stages, the closed page shows only the hand-off message.
            $handoffReceipt = ['title' => $requestStageReceipt['title'], 'message' => $requestStageReceipt['message']];
        }
    @endphp

    @if($handoffReceipt && ! $isPreview)
        <div class="request-flow-page" data-app-page-back-destination="{{ route('dashboard') }}">
            <div class="alert alert-success mb-0 d-flex align-items-start gap-3" role="status" data-po-handoff-confirmation>
                <div class="flex-grow-1">
                    <strong>{{ $handoffReceipt['title'] }}</strong>
                    {{ $handoffReceipt['message'] }}
                </div>
                <button class="btn handoff-close-button" type="button" data-app-page-back aria-label="Close confirmation and return to Home" title="Close and return to Home">
                    <x-ui.icon name="x-circle" size="20" />
                </button>
            </div>
        </div>
    @else
    <div @class(['po-focus-page' => $poFocus, 'request-flow-page' => ! $poFocus, 'request-preview-mode' => $isPreview])>
    <header class="page-header purchase-request-page-header">
        <div class="purchase-request-heading">
            <nav class="request-breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('purchase-requests.index') }}">Requests</a>
                <span aria-hidden="true">/</span>
                <span aria-current="page">{{ $purchaseRequest->request_number }}</span>
            </nav>
            <div class="purchase-request-title-row">
                <h1 class="mb-0">{{ $purchaseRequest->title ?: $purchaseRequest->request_number }}</h1>
                <span class="po-focus-stage-pill"><x-ui.icon name="request" size="18" /> {{ $poFocus ? 'Purchase order' : $workflowStage }}</span>
            </div>
            
        </div>
        @if($poFocus)
            <div class="po-focus-progress" aria-label="Purchase order preparation progress">
                <span><b aria-hidden="true">✓</b>Request approved</span>
                <span><b aria-hidden="true">✓</b>Supplier selected</span>
                <span class="is-current"><b aria-hidden="true"><x-ui.icon name="request" size="20" /></b>Purchase order<small>Current stage</small></span>
            </div>
        @else
        <div class="flow-header-tools">
            <div class="po-focus-progress flow-progress" aria-label="Request workflow progress">
                @for($stepIndex = max(0, $workflowProgressIndex - 1); $stepIndex <= min(count($workflowProgressSteps) - 1, $workflowProgressIndex + 1); $stepIndex++)
                    @php
                        $progressState = $stepIndex < $workflowProgressIndex ? 'complete' : ($stepIndex === $workflowProgressIndex ? 'current' : 'pending');
                    @endphp
                    <span @class(['is-current' => $progressState === 'current', 'is-pending' => $progressState === 'pending'])>
                        <b aria-hidden="true">{{ $progressState === 'complete' ? '✓' : $stepIndex + 1 }}</b>
                        {{ $progressState === 'current' ? $workflowStage : $workflowProgressSteps[$stepIndex]['label'] }}
                        @if($progressState === 'current')<small>Current stage</small>@endif
                    </span>
                @endfor
            </div>
        <div class="d-flex flex-wrap gap-2 page-actions">

            @can('update', $purchaseRequest)
                <a class="btn btn-outline-primary" href="{{ route('purchase-requests.edit', $purchaseRequest) }}">Edit Request</a>
            @endcan
            @can('submit', $purchaseRequest)
                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#submit-request-modal">Submit for Screening</button>
            @endcan
            @can('withdraw', $purchaseRequest)
                <button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#withdraw-request-modal">Withdraw Request</button>
            @endcan
        </div>
        </div>
        @endif
    </header>
    @include('purchase-requests.partials.withdrawal')
    @can('submit', $purchaseRequest)
        <div class="modal fade" id="submit-request-modal" tabindex="-1" aria-labelledby="submit-request-heading" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <form class="modal-content" method="POST" action="{{ route('purchase-requests.submit', $purchaseRequest) }}">
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="submit-request-heading">Submit for Screening</h2>
                        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label" for="submission-remarks">Remarks <span class="text-muted fw-normal">(optional)</span></label>
                        <textarea class="form-control mb-3" id="submission-remarks" name="submission_remarks" rows="2" maxlength="5000" placeholder="Notes for the screening team">{{ old('submission_remarks') }}</textarea>
                        <x-workflow-signature-pad
                            id="request-submit-signature"
                            label="Requestor signature"
                            instruction="Draw your signature, then select Sign & Submit."
                        />
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn btn-primary" type="submit">Sign &amp; Submit</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan

    @if($shouldAutoOpenPurchaseOrderPreview)
        <section class="alert alert-success border-0 shadow-sm p-0 overflow-hidden mb-3" id="purchase-order-success" role="status" aria-labelledby="purchase-order-success-heading">
            <div class="p-3 p-lg-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <x-ui.icon name="check-circle" size="24" />
                    <h2 class="h5 font-heading mb-0" id="purchase-order-success-heading">Purchase Order Generated Successfully</h2>
                </div>
                <div class="row g-3">
                    <div class="col-sm-6 col-xl-3"><div class="detail-label">PO Number</div><div class="fw-semibold">{{ $purchaseRequest->purchaseOrder->po_number }}</div></div>
                    <div class="col-sm-6 col-xl-3"><div class="detail-label">Supplier</div><div class="fw-semibold">{{ $purchaseRequest->purchaseOrder->supplier?->name ?? 'Unavailable' }}</div></div>
                    <div class="col-sm-6 col-xl-3"><div class="detail-label">Grand Total</div><div class="fw-semibold">&#8369; {{ number_format((float) $purchaseRequest->purchaseOrder->total_amount, 2) }}</div></div>
                    <div class="col-sm-6 col-xl-3"><div class="detail-label">Status</div><div class="fw-semibold">{{ str($purchaseRequest->purchaseOrder->status)->replace('_', ' ')->title() }}</div></div>
                </div>
            </div>
            <div class="po-success-next-action p-3 p-lg-4">
                <div class="section-kicker">Next workflow action</div>
                <div class="row g-3 align-items-center">
                    <div class="col-lg">
                        <div class="fw-bold mb-1">Next Step: Purchase Order Checking</div>
                        <div class="small mb-1"><strong>Responsible Role:</strong> Procurement checker</div>
                        <div class="small">Preview the saved Purchase Order now. Printing and Budget Request preparation become available after final approval.</div>
                    </div>
                    <div class="col-lg-auto po-success-document-actions no-print">
                        <div class="small mb-2">PO document includes saved PO values and approved supplier bank details.</div>
                        <div class="d-flex flex-wrap gap-2">
                            <button class="btn btn-sm btn-outline-primary" type="button" data-po-preview-url="{{ route('purchase-orders.preview', $purchaseRequest->purchaseOrder) }}">View Purchase Order</button>
                            @if($purchaseRequest->purchaseOrder->isApproved())
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('purchase-orders.document', ['purchaseRequest' => $purchaseRequest, 'mode' => 'print']) }}" target="_blank" rel="noopener">Print Purchase Order</a>
                                <a class="btn btn-sm btn-primary" href="{{ route('purchase-orders.document', ['purchaseRequest' => $purchaseRequest, 'mode' => 'export']) }}" target="_blank" rel="noopener">Export PO</a>
                            @else
                                <button class="btn btn-sm btn-outline-secondary" type="button" disabled>Print after approval</button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </section>
    @endif
    @if($purchaseRequest->purchaseOrder)
        @include('purchase-requests.partials.purchase-order-overview')
    @endif

    @if($purchaseRequest->status === \App\Enums\PurchaseRequestStatus::Returned && $latestReturn)
        <div class="alert alert-warning"><strong>Returned for correction:</strong> {{ $latestReturn->remarks }}</div>
    @endif

    @if($poFocus)
        @include('purchase-requests.partials.purchase-order-focus')
    @else
    <section class="po-focus-hero flow-stage-hero" id="request-workflow" aria-labelledby="workflow-progress-heading">
        <div class="po-focus-hero-icon"><x-ui.icon name="flow" size="27" /></div>
        <div class="po-focus-hero-copy">
            
            <div class="section-kicker">Current step</div>
            <h2 id="workflow-progress-heading">{{ $workflowStage }}</h2>
            <p>{{ $nextAction }}</p>
        </div>
        <dl class="po-focus-hero-facts">
            <div><dt>Responsible role</dt><dd>{{ $responsibleRoleLabel }}</dd></div>
            <div><dt>Live status</dt><dd><x-status-badge :status="$purchaseRequest->status" /></dd></div>
            
        </dl>
    </section>

    <div class="purchase-request-section">
        <section class="request-information-section mb-3" aria-label="Request information and summary">
            <div class="row g-3">
            <div class="{{ $hideQuotationSummary ? 'col-lg-12' : 'col-lg-8' }} request-information-main">
                <div class="card border-0 shadow-none request-information-card">
                <div class="card-header bg-white"><div class="section-kicker">Request profile</div><div class="fw-semibold">Request Information</div></div>
                <div class="card-body">
                    <dl class="po-focus-facts flow-request-facts">
                        <div><dt>Requested by</dt><dd>{{ $purchaseRequest->requestor->name }}</dd></div>
                        <div><dt>Department</dt><dd>{{ $purchaseRequest->requestor->department ?: 'Not recorded' }}</dd></div>
                        <div><dt>Required date</dt><dd>{{ $purchaseRequest->required_date?->format('M d, Y') ?? 'Not recorded' }}</dd></div>
                        <div><dt>Delivery location</dt><dd>{{ $purchaseRequest->delivery_location }}</dd></div>
                    </dl>
                    <details class="flow-request-more">
                        <summary>More request information</summary>
                        <dl class="request-info-grid mb-0">
                            <div class="request-info-item"><dt class="request-info-label">Request Number</dt><dd class="request-info-value">{{ $purchaseRequest->request_number }}</dd></div>
                            <div class="request-info-item"><dt class="request-info-label">Request Title</dt><dd class="request-info-value">{{ $purchaseRequest->title }}</dd></div>
                            <div class="request-info-item"><dt class="request-info-label">Request Type</dt><dd class="request-info-value">{{ $requestTypeLabel }}</dd></div>
                            <div class="request-info-item"><dt class="request-info-label">Request Category</dt><dd class="request-info-value{{ $purchaseRequest->request_category ? '' : ' request-info-value--muted' }}">@if($purchaseRequest->request_category){{ \App\Models\PurchaseRequest::REQUESTED_TEAMS[$purchaseRequest->requested_team] ?? $purchaseRequest->requested_team }} · {{ \App\Models\PurchaseRequest::requestCategoryLabel($purchaseRequest->requested_team, $purchaseRequest->request_category) }}@if($purchaseRequest->request_scope) <span class="badge text-bg-light border ms-1">{{ \App\Models\PurchaseRequest::REQUEST_SCOPES[$purchaseRequest->request_scope] ?? $purchaseRequest->request_scope }}</span>@endif @else Not recorded @endif</dd></div>
                            <div class="request-info-item"><dt class="request-info-label">Request Date</dt><dd class="request-info-value">{{ $purchaseRequest->created_at->format('M d, Y') }}</dd></div>
                            @can('setScreeningPriority', $purchaseRequest)
                                <div class="request-info-item request-info-item--wide request-info-item--priority">
                                    <dt class="request-info-label"><label for="screening-priority-level">Priority</label></dt>
                                    <dd class="request-info-value">
                                        <form class="screening-priority-form" method="POST" action="{{ route('request-flow.priority.update', $purchaseRequest) }}">
                                            @csrf
                                            @method('PATCH')
                                            <select class="form-select form-select-sm @error('priority_level') is-invalid @enderror" id="screening-priority-level" name="priority_level" required>
                                                <option value="" disabled @selected(! $purchaseRequest->priority_level)>Select priority</option>
                                                @foreach(\App\Models\PurchaseRequest::PRIORITY_LEVELS as $priorityValue => $priorityLabel)
                                                    <option value="{{ $priorityValue }}" @selected($purchaseRequest->priority_level === $priorityValue)>{{ $priorityLabel }}</option>
                                                @endforeach
                                            </select>
                                            <button class="btn btn-sm btn-outline-primary" type="submit">Save priority</button>
                                        </form>
                                        @error('priority_level')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                                    </dd>
                                </div>
                            @elsecan('viewPriority', $purchaseRequest)
                                <div class="request-info-item"><dt class="request-info-label">Priority</dt><dd class="request-info-value{{ $purchaseRequest->priority_level ? '' : ' request-info-value--muted' }}">{{ \App\Models\PurchaseRequest::priorityLabel($purchaseRequest->priority_level) ?? 'Not recorded' }}</dd></div>
                            @endcan
                            <div class="request-info-item"><dt class="request-info-label">Campaign</dt><dd class="request-info-value">{{ $purchaseRequest->department_campaign }}</dd></div>
                            @if(in_array($purchaseRequest->status, [\App\Enums\PurchaseRequestStatus::ForTeamScreening, \App\Enums\PurchaseRequestStatus::ForItScreening, \App\Enums\PurchaseRequestStatus::ForPremisesScreening], true))
                                <div class="request-info-item"><dt class="request-info-label">Screening Owner</dt><dd class="request-info-value">{{ $purchaseRequest->assignedUser?->name ?? 'Unclaimed' }}</dd></div>
                            @endif
                            <div class="request-info-item request-info-item--wide"><dt class="request-info-label">Purpose</dt><dd class="request-info-value text-break">{{ $purchaseRequest->purpose }}</dd></div>
                            <div class="request-info-item request-info-item--wide"><dt class="request-info-label">Remarks</dt><dd class="request-info-value text-break{{ $purchaseRequest->remarks ? '' : ' request-info-value--muted' }}">{{ $purchaseRequest->remarks ?: 'N/A' }}</dd></div>
                        </dl>
                        <div class="request-secondary-metadata">
                            <span><strong>Created</strong> {{ $purchaseRequest->created_at->format('M d, Y g:i A') }}</span>
                            @if($purchaseRequest->submitted_at)
                                <span><strong>Submitted</strong> {{ $purchaseRequest->submitted_at->format('M d, Y g:i A') }}</span>
                            @endif
                        </div>
                    </details>
                </div>
            </div>
            </div>
            @unless($hideQuotationSummary)
                <div class="col-lg-4 request-summary-column">
                    @include('purchase-requests.partials.quotation-summary')
                </div>
            @endunless
            </div>
        </section>
    </div>

    <section class="card mb-3" aria-labelledby="visible-workflow-remarks-heading">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <h2 class="h5 mb-0" id="visible-workflow-remarks-heading">Workflow remarks</h2>
            <button class="btn btn-sm btn-outline-secondary workflow-remarks-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#workflow-remarks-body" aria-expanded="true" aria-controls="workflow-remarks-body">
                <x-ui.icon name="chevron-right" size="14" />
                <span class="workflow-remarks-toggle-close">Close</span><span class="workflow-remarks-toggle-expand">Expand</span>
            </button>
        </div>
        <div class="collapse show" id="workflow-remarks-body">
            <div class="card-body"><x-workflow-remarks :request="$purchaseRequest" :timeline="true" /></div>
        </div>
    </section>
    <div class="card mb-3 purchase-request-content-block requested-items-card" id="requested-items">
        <div class="card-header bg-white">
            <div class="section-kicker">Request contents</div>
            <div class="fw-semibold">Requested Items</div>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0 request-items-table">
                @php
                    $hasItemEstimates = $purchaseRequest->items->contains(fn ($item) => $item->quantity !== null || $item->unit !== null || $item->estimated_unit_price !== null);
                    $vatQuotations = $purchaseRequest->quotations->sortByDesc(fn ($quotation) => [$quotation->is_approved ? 2 : 0, $quotation->is_recommended ? 1 : 0, $quotation->id]);
                @endphp
                <thead class="table-light"><tr><th>Description</th><th>Quotation</th>@if($hasItemEstimates)<th class="text-end">Quantity</th><th>Unit</th><th class="text-end">Unit Price</th><th>VAT Type</th><th class="text-end">Total</th>@endif</tr></thead>
                <tbody>
                @foreach($purchaseRequest->items as $item)
                    @php
                        $itemQuotation = $vatQuotations->first(fn ($quotation) => filled($quotation->purchase_request_item_id) && (int) $quotation->purchase_request_item_id === (int) $item->id)
                            ?? $vatQuotations->first(fn ($quotation) => blank($quotation->purchase_request_item_id))
                            ?? $vatQuotations->first();
                        $vatTreatment = $itemQuotation?->vatBreakdown()['treatment'] ?? null;
                        $vatLabel = match ($vatTreatment) {
                            'EXEMPT' => 'Non-Vat',
                            'EXCLUSIVE' => 'VAT (12%)',
                            'INCLUSIVE' => 'VAT Inclusive',
                            default => '—',
                        };
                    @endphp
                    <tr>
                        <td>
                            <div class="request-item-title">{{ $item->description }}</div>
                            @if($item->reference_url)
                                <div class="request-item-link"><x-ui.icon name="link" size="13" /><a href="{{ $item->reference_url }}" target="_blank" rel="noopener noreferrer">Product reference</a></div>
                            @endif
                            @foreach($item->attachments->sortBy(fn ($attachment) => [$attachment->category === \App\Models\PurchaseRequestItem::ATTACHMENT_EMAIL_APPROVAL ? 0 : ($attachment->category === \App\Models\PurchaseRequestItem::ATTACHMENT_QUOTATION ? 1 : 2), $attachment->id]) as $attachment)
                                <div class="request-item-link"><x-attachment-link :attachment="$attachment" :label="\App\Models\PurchaseRequestItem::attachmentLabel($attachment->category)" thumbnail /></div>
                            @endforeach
                        </td>
                        <td>{{ $item->brand_specifications ?: '—' }}</td>
                        @if($hasItemEstimates)
                            <td class="text-end">{{ $item->quantity === null ? '—' : number_format((float) $item->quantity, 2) }}</td>
                            <td>{{ $item->unit ?: '—' }}</td>
                            <td class="text-end text-nowrap">{{ $item->estimated_unit_price === null ? '—' : '₱ '.number_format((float) $item->estimated_unit_price, 2) }}</td>
                            <td class="text-nowrap">{{ $vatLabel }}</td>
                            <td class="text-end text-nowrap fw-semibold">{{ $item->estimated_total === null ? '—' : '₱ '.number_format((float) $item->estimated_total, 2) }}</td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
                @if($hasItemEstimates && $purchaseRequest->total_amount !== null)<tfoot><tr><th class="text-end" colspan="6">Request estimate</th><th class="text-end text-nowrap request-grand-total">₱ {{ number_format((float) $purchaseRequest->total_amount, 2) }}</th></tr></tfoot>@endif
            </table>
        </div>
    </div>


    @if($purchaseRequest->budgetRequest)
        @php $budget = $purchaseRequest->budgetRequest; @endphp
        <section class="card border-0 shadow-sm mb-3 budget-request-document" id="budget-request-document" aria-labelledby="budget-request-heading">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <div class="section-kicker">Finance document</div>
                    <div class="fw-semibold" id="budget-request-heading">Budget Request {{ $budget->budget_request_number ?? '(BR code issued at Final budget)' }}</div>
                </div>
                <div class="d-flex flex-wrap gap-2 no-print">
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('budget-requests.document', $budget) }}" target="_blank" rel="noopener">Open in new tab</a>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-print-budget-request>Print / Save PDF</button>
                </div>
            </div>
            <div class="card-body">
                @include('budget-requests.partials.br-document-sheet', ['budget' => $budget, 'purchaseRequest' => $purchaseRequest])
            </div>
        </section>
    @endif

    @if($purchaseRequest->screening)
        <div class="card border-0 shadow-sm mb-3 screening-evidence-card">
            <div class="card-header bg-white"><div class="section-kicker">Department review</div><div class="fw-semibold">Screening Evidence and Released Assets</div></div>
            <div class="card-body">
                <dl class="screening-facts mb-3">
                    <div><dt>Team</dt><dd>{{ $purchaseRequest->screening->team }}</dd></div>
                    <div><dt>Decision</dt><dd><span class="screening-decision-badge">{{ str($purchaseRequest->screening->decision)->title() }}</span></dd></div>
                    <div><dt>Screened By</dt><dd>{{ $purchaseRequest->screening->screener->name }}</dd></div>
                    <div><dt>Decision Date</dt><dd>{{ $purchaseRequest->screening->decided_at->format('M d, Y g:i A') }}</dd></div>
                    <div class="screening-fact--wide"><dt>Evidence</dt><dd>{{ $purchaseRequest->screening->evidence ?: 'N/A' }}</dd></div>
                    @foreach($purchaseRequest->screening->attachments as $attachment)
                        <div class="screening-fact--wide screening-attachment"><dt>Attachment</dt><dd><x-attachment-link :attachment="$attachment" thumbnail /></dd></div>
                    @endforeach
                </dl>
                @if($purchaseRequest->screening->assets->isNotEmpty())
                    <div class="table-responsive">
                        <table class="table table-sm align-middle released-assets-table">
                            <thead><tr><th>Asset tag</th><th>Item code</th><th>Description</th><th>Serial</th><th>Location</th><th>Source</th><th>Release</th><th>Released by</th><th>Recipient</th></tr></thead>
                            <tbody>
                            @foreach($purchaseRequest->screening->assets as $asset)
                                <tr>
                                    <td>{{ $asset->asset_tag }}</td><td>{{ $asset->item_code }}</td><td>{{ $asset->description }}</td>
                                    <td>{{ $asset->serial_number ?: '—' }}</td><td>{{ $asset->location }}</td><td>{{ str($asset->source)->replace('_', ' ')->title() }}</td>
                                    <td>{{ $purchaseRequest->status === \App\Enums\PurchaseRequestStatus::ForInventoryRelease ? 'Pending release' : $asset->release_date->format('M d, Y') }}</td><td>{{ $purchaseRequest->status === \App\Enums\PurchaseRequestStatus::ForInventoryRelease ? 'Awaiting Inventory' : $asset->releaser->name }}</td><td>{{ $asset->recipient }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if($quotations->isNotEmpty() || $purchaseRequest->selectedSupplier)
        <div class="purchase-request-section" id="supplier-quotations" aria-label="Canvassing and quotations">
            <details class="supplier-quotations-disclosure mb-3" id="supplier-quotations-panel" @if($purchaseRequest->status === \App\Enums\PurchaseRequestStatus::ForSupplierApproval || session('quotation_saved')) open @endif>
                <summary><span><strong>All quotations <span class="badge text-bg-primary ms-1" aria-label="{{ $quotations->count() }} saved quotations">{{ $quotations->count() }}</span></strong><small>{{ $quotations->count() }} quotation{{ $quotations->count() === 1 ? '' : 's' }} · Purchasing recommendation: {{ $quotations->firstWhere('is_recommended', true)?->supplier?->name ?? 'Not recorded' }}@if($screeningRecommendedSupplierId) · Screening recommendation: {{ $quotations->firstWhere('supplier_id', (int) $screeningRecommendedSupplierId)?->supplier?->name ?? 'Not recorded' }}@endif</small></span><span class="text-primary fw-semibold"><span class="when-closed">Show quotations</span><span class="when-open">Hide quotations</span></span></summary>
                <?php $quotationSaved = session('quotation_saved') ?? false; ?>
                <?php if($quotationSaved): ?>
                    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                        <strong>Quotations saved!</strong> Your quotations have been recorded. You can continue to review or proceed to the next workflow step.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
        <div class="card border-0 shadow-sm mb-3 quotation-comparison-card">
            <div class="card-header bg-white"><div class="section-kicker">Supplier comparison</div><div class="fw-semibold">Canvassing &amp; Quotations</div><small class="text-muted fw-normal d-block">Compare every submitted quotation. Item-level rows are retained when a supplier submitted a bulk quotation.</small></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 quotation-comparison-table">
                    <thead class="table-light"><tr><th>Reference</th><th>Supplier</th><th>Quoted Item</th><th>Amount / Line Total</th><th>Lead Time</th><th>Warranty</th><th>Quotation</th><th>Recommendation</th><th>Workflow status</th><th>Actions</th></tr></thead>
                    <tbody>
                    @foreach($quotations as $quotation)
                        @php ($quotationDestination = $quotation->quotationDestination()); @endphp
                        <tr @class(['quotation-row--recommended' => $quotation->is_recommended])>
                            <td class="text-nowrap">Q-{{ str_pad((string) $quotation->id, 5, '0', STR_PAD_LEFT) }}<div class="small text-muted">{{ $purchaseRequest->request_type }}</div></td><td class="quotation-supplier-name">{{ $quotation->supplier->name }}</td>
                            <td>{{ $quotation->item?->description ?? $quotation->item_or_service }}</td>
                            <td class="text-nowrap quotation-price">₱ {{ number_format($quotation->vatBreakdown()['total'], 2) }}</td>
                            <td>{{ $quotation->delivery_lead_time ?: '—' }}</td>
                            <td>{{ $quotation->warranty ?: '—' }}</td>
                            <td>
                                @if($quotationDestination)
                                    <a class="quotation-document-link" href="{{ $quotationDestination }}" target="_blank" rel="noopener noreferrer"><x-ui.icon name="eye" size="13" />Open</a>
                                @else
                                    No quotation document provided.
                                @endif
                            </td>
                            <td>
                                @if($screeningRecommendedSupplierId && (int) $screeningRecommendedSupplierId === (int) $quotation->supplier_id)<span class="badge text-bg-info">Screening recommended</span>@endif
                                @if($quotation->is_recommended)<span class="badge quotation-badge-recommended">Purchasing recommended</span>@endif
                                @if($purchasingCanRecommendQuotation && ! $quotation->is_recommended)
                                    <form method="POST" action="{{ route('canvassing.quotations.recommend', [$purchaseRequest, $quotation]) }}" class="mt-1">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-primary" type="submit">Recommend to President</button>
                                    </form>
                                @endif
                                @if(! $quotation->is_recommended && ! $teamCanRecommendQuotation && ! $purchasingCanRecommendQuotation)<span class="text-muted">{{ $quotation->recommendationStatus() }}</span>@endif
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    @if((int) $purchaseRequest->selected_supplier_id === (int) $quotation->supplier_id)<span class="badge text-bg-primary">Selected</span>@endif
                                    @if($quotation->is_approved)<span class="badge text-bg-success">Approved</span>@else<span class="text-muted">Pending approval</span>@endif
                                </div>
                            </td>
                            <td class="text-nowrap"><button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#quotation-preview-{{ $quotation->id }}">Preview</button>@if(auth()->id() === $quotation->created_by && ! $quotation->is_approved)<button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#quotation-edit-{{ $quotation->id }}">Edit</button>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white">
                <div class="detail-label mb-2">Supplier totals</div>
                <div class="supplier-total-list">
                    @foreach($quotations->groupBy('supplier_id') as $supplierQuotes)
                        <span>{{ $supplierQuotes->first()->supplier->name }}{{ $supplierQuotes->count() > 1 ? ' ('.$supplierQuotes->count().' quoted lines)' : '' }}: <strong>₱ {{ number_format((float) $supplierQuotes->sum(fn ($quote) => $quote->vatBreakdown()['total']), 2) }}</strong></span>
                    @endforeach
                </div>
                @if($duplicateQuotationCount > 0)
                    <div class="small text-muted mt-2">{{ $duplicateQuotationCount }} repeated quotation record{{ $duplicateQuotationCount === 1 ? '' : 's' }} retained in the database and consolidated for review.</div>
                @endif
            </div>
        </div>
            </details>
            @include('purchase-requests.partials.quotation-modals')
        </div>
    @endif

    @include('purchase-requests.partials.quotation-entry-panel')

    @if($returnStage === 'supplier-approval' && (! $canDecidePresidentApproval || $purchaseRequest->status !== \App\Enums\PurchaseRequestStatus::ForSupplierApproval))
        <div class="alert alert-info no-print"><strong>Read-only supplier approval view.</strong> You can inspect the quotations and approval history, but no decision is available because you are not the assigned approver or this step is no longer actionable.</div>
    @endif

    @can('reviewPresident', $purchaseRequest)
        @if($purchaseRequest->status === \App\Enums\PurchaseRequestStatus::ForSupplierApproval)
            <div class="card action-panel mb-3 no-print">
                <div class="card-header fw-semibold" id="supplier-approval-actions">President Supplier Review</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('president-approvals.supplier.decision', $purchaseRequest) }}" data-validation-context="supplier-approval">
                        @csrf
                        <input type="hidden" name="_validation_context" value="supplier-approval">
                        <div class="mb-3">
                            @php ($recommendedQuotation = $quotations->firstWhere('is_recommended', true)); @endphp
                            @if($recommendedQuotation)
                                <div class="alert alert-info py-2 mb-3"><strong>Purchasing recommendation:</strong> {{ $recommendedQuotation->supplier->name }}. This is information for your review; it is not an automatic approval.</div>
                            @endif
                            @if($quotations->isEmpty())
                                <div class="alert alert-warning mb-0" role="status">
                                    <strong>No supplier quotation or pricing provided.</strong>
                                    <p class="mb-0 mt-1">Purchasing submitted this request for your attention. Acknowledge it and send it back to Purchasing for pricing follow-up, or return it with your instructions.</p>
                                </div>
                            @else
                            <fieldset>
                                <legend class="form-label mb-2">President Supplier Decision <span class="text-danger">*</span></legend>
                                <div class="small text-muted mb-2">Select the specific quotation you are approving. Any valid submitted quotation may be selected.</div>
                                <div class="table-responsive border rounded overflow-hidden">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead class="table-light"><tr><th>Supplier / Item</th><th>Amount</th><th>Lead time</th><th>Warranty</th><th>Recommendation</th><th></th></tr></thead>
                                        <tbody>
                                        @foreach($quotations as $quotation)
                                            <tr class="{{ $quotation->is_recommended ? 'table-info' : '' }}">
                                                <td><label class="d-flex align-items-center gap-2 mb-0"><input class="form-check-input" type="radio" name="quotation_id" value="{{ $quotation->id }}" @checked(old('quotation_id') == $quotation->id)><span class="fw-semibold">{{ $quotation->supplier?->name ?? 'Unavailable supplier' }}</span></label><div class="small text-muted ms-4">{{ $quotation->item?->description ?? $quotation->item_or_service }}</div></td>
                                                <td class="fw-semibold">&#8369; {{ number_format($quotation->vatBreakdown()['total'], 2) }}</td>
                                                <td>{{ $quotation->delivery_lead_time ?: 'Not provided' }}</td>
                                                <td>{{ $quotation->warranty ?: 'Not provided' }}</td>
                                                <td>@if($quotation->is_recommended)<span class="badge text-bg-info">Recommended</span>@else<span class="text-muted">—</span>@endif</td>
                                                <td><button class="btn btn-sm btn-outline-primary text-nowrap" type="button" data-bs-toggle="modal" data-bs-target="#quotation-details-{{ $quotation->id }}">View Details</button></td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </fieldset>
                            @endif
                        </div>
                        <div class="mb-3"><label class="form-label" for="supplier-remarks">Remarks <span class="text-muted fw-normal">(optional)</span></label><textarea class="form-control" id="supplier-remarks" name="remarks" rows="3">{{ old('remarks') }}</textarea></div>
                        <div class="approval-signature-pad mt-3" data-signature-pad aria-labelledby="president-signature-heading">
                            <span class="approval-signature-box__label" id="president-signature-heading">Electronic signature</span>
                            <strong class="approval-signature-box__account">Sign as: {{ auth()->user()->name }}</strong>
                            <canvas class="approval-signature-pad__canvas" data-signature-canvas width="760" height="190" tabindex="0" aria-label="Draw your electronic signature"></canvas>
                            <input type="hidden" name="signature_name" value="{{ auth()->user()->name }}"><input type="hidden" name="signature_data" data-signature-data>
                            <div class="approval-signature-pad__footer"><small>Draw your signature to record your President review decision.</small><button class="btn btn-sm btn-outline-primary" type="button" data-signature-clear>Clear signature</button></div>
                            <div class="field-validation-message" data-signature-client-error hidden>Draw your signature before confirming this decision.</div>
                            @error('signature_data')<div class="field-validation-message">{{ $message }}</div>@enderror
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            @if($quotations->isEmpty())
                                <button class="btn btn-primary" type="submit" name="decision" value="ACKNOWLEDGE">Acknowledge &amp; return to Purchasing</button>
                            @else
                                <button class="btn btn-success" type="submit" name="decision" value="APPROVE">Approve Selected Supplier</button>
                            @endif
                            <button class="btn btn-outline-warning" type="button" data-bs-toggle="modal" data-bs-target="#president-return-modal">Return to Canvassing</button>
                        </div>
                    </form>
                </div>
            </div>

            @foreach($quotations as $quotation)
                @php
                    $quotationDestination = $quotation->quotationDestination();
                    $quotationBreakdown = $quotation->vatBreakdown();
                    $quotedItemLabel = $quotation->item?->description ?? $quotation->item_or_service ?? 'Quotation item';
                    $submittedBy = $quotation->creator?->name ?: 'Unknown';
                    $submittedDate = $quotation->quoted_at?->format('M d, Y') ?: 'Date not recorded';
                    $vatLabel = $quotationBreakdown['rate'] > 0 ? 'VAT ('.rtrim(rtrim(number_format($quotationBreakdown['rate'], 2, '.', ''), '0'), '.').'%)' : 'Non-Vat';
                    $recommendationLabel = $quotation->is_recommended ? 'Recommended by Purchasing.' : 'Not recommended by Purchasing.';
                @endphp
                <div class="modal fade quotation-details-modal" id="quotation-details-{{ $quotation->id }}" tabindex="-1" aria-labelledby="quotation-details-title-{{ $quotation->id }}" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
                        <div class="modal-header quotation-details-modal__header"><div class="quotation-details-modal__heading"><span class="quotation-details-modal__header-icon"><x-ui.icon name="request" size="28" /></span><div><h2 class="modal-title" id="quotation-details-title-{{ $quotation->id }}">Quotation Details</h2><p class="quotation-details-modal__subtitle mb-0">{{ $quotedItemLabel }}</p></div></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                        <div class="modal-body quotation-details-modal__body">
                            <section class="quotation-details-modal__metadata" aria-label="Quotation metadata"><div class="quotation-details-modal__metadata-item quotation-details-modal__metadata-item--item"><span class="quotation-details-modal__metadata-title">Quoted item</span><strong>{{ $quotedItemLabel }}</strong></div><div class="quotation-details-modal__metadata-item"><x-ui.icon name="users" size="25" /><div><span class="quotation-details-modal__metadata-title">Supplier</span><strong>{{ $quotation->supplier?->name ?? 'Unavailable supplier' }}</strong></div></div><div class="quotation-details-modal__metadata-item"><x-ui.icon name="calendar" size="25" /><div><span class="quotation-details-modal__metadata-title">Submitted</span><strong>{{ $submittedDate }}</strong><small>by {{ $submittedBy }}</small></div></div></section>
                            <div class="quotation-details-modal__grid">
                                <section class="quotation-details-modal__financial" aria-labelledby="financial-summary-{{ $quotation->id }}"><h3 id="financial-summary-{{ $quotation->id }}">Financial Summary</h3>
                                    <dl class="quotation-details-modal__details-list quotation-details-modal__financial-list"><div><dt><x-ui.icon name="wallet" size="22" />Net of VAT</dt><dd>&#8369; {{ number_format($quotationBreakdown['base'], 2) }}</dd></div><div><dt><x-ui.icon name="currency" size="22" />{{ $vatLabel }}</dt><dd>&#8369; {{ number_format($quotationBreakdown['vat'], 2) }}@if($quotationBreakdown['rate'] > 0) <small>({{ rtrim(rtrim(number_format($quotationBreakdown['rate'], 2, '.', ''), '0'), '.') }}%)</small>@endif</dd></div><div class="quotation-details-modal__total"><dt><x-ui.icon name="currency" size="22" />Total</dt><dd>&#8369; {{ number_format($quotationBreakdown['total'], 2) }}</dd></div></dl>
                                    <dl class="quotation-details-modal__details-list quotation-details-modal__terms-list"><div><dt><x-ui.icon name="hourglass" size="21" />Lead time</dt><dd>{{ $quotation->delivery_lead_time ?: 'Not provided' }}</dd></div><div><dt><x-ui.icon name="shield-check" size="21" />Warranty</dt><dd>{{ $quotation->warranty ?: 'Not provided' }}</dd></div><div><dt><x-ui.icon name="receive" size="21" />Delivery terms</dt><dd>{{ $quotation->delivery_terms ?: 'Not provided' }}</dd></div><div><dt><x-ui.icon name="check-circle" size="21" />Brand / quality</dt><dd>{{ $quotation->brand_quality ?: 'Not provided' }}</dd></div></dl>
                                    <dl class="quotation-details-modal__details-list quotation-details-modal__notes-list"><div><dt><x-ui.icon name="building" size="21" />Supplier bank details</dt><dd class="text-break">{{ $quotation->bank_details ?: 'Not provided' }}</dd></div><div><dt><x-ui.icon name="receipt" size="21" />Remarks</dt><dd>{{ $quotation->remarks ?: 'N/A' }}</dd></div></dl>
                                </section>
                                <aside class="quotation-details-modal__summary-card" aria-labelledby="quotation-summary-{{ $quotation->id }}"><div class="quotation-details-modal__summary-heading"><span><x-ui.icon name="receipt" size="23" /></span><h3 id="quotation-summary-{{ $quotation->id }}">Summary</h3></div><dl class="quotation-details-modal__summary-list"><div><dt>Supplier</dt><dd>{{ $quotation->supplier?->name ?? 'Unavailable supplier' }}</dd></div><div><dt>Quoted item</dt><dd>{{ $quotedItemLabel }}</dd></div><div><dt>Net of VAT</dt><dd>&#8369; {{ number_format($quotationBreakdown['base'], 2) }}</dd></div><div><dt>{{ $vatLabel }}</dt><dd>&#8369; {{ number_format($quotationBreakdown['vat'], 2) }}</dd></div><div class="quotation-details-modal__summary-total"><dt>Total</dt><dd>&#8369; {{ number_format($quotationBreakdown['total'], 2) }}</dd></div><div><dt>Lead time</dt><dd>{{ $quotation->delivery_lead_time ?: 'Not provided' }}</dd></div><div><dt>Warranty</dt><dd>{{ $quotation->warranty ?: 'Not provided' }}</dd></div><div><dt>Delivery terms</dt><dd>{{ $quotation->delivery_terms ?: 'Not provided' }}</dd></div><div><dt>Brand / quality</dt><dd>{{ $quotation->brand_quality ?: 'Not provided' }}</dd></div><div><dt>Supplier bank details</dt><dd class="text-break">{{ $quotation->bank_details ?: 'Not provided' }}</dd></div><div><dt>Remarks</dt><dd>{{ $quotation->remarks ?: 'N/A' }}</dd></div></dl><div class="quotation-details-modal__notice"><x-ui.icon name="exclamation-circle" size="24" /><div><strong>This quotation is for reference only.</strong><span>Purchasing recommendation: {{ $recommendationLabel }}</span></div></div></aside>
                            </div>
                        </div>
                        <div class="modal-footer quotation-details-modal__footer">@if($quotationDestination)<a class="btn btn-outline-primary quotation-details-modal__download" href="{{ $quotationDestination }}" download><x-ui.icon name="receipt" size="17" />Download PDF</a>@else<button class="btn btn-outline-secondary quotation-details-modal__download" type="button" disabled><x-ui.icon name="receipt" size="17" />Download PDF</button>@endif<div class="quotation-details-modal__footer-actions">@if($quotationDestination)<a class="btn btn-primary" href="{{ $quotationDestination }}" target="_blank" rel="noopener noreferrer">Open Quotation <span aria-hidden="true">↗</span></a>@else<span class="small text-muted">No quotation document provided.</span>@endif<button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button></div></div>
                    </div></div>
                </div>
            @endforeach            <div class="modal fade" id="president-return-modal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('president-approvals.supplier.decision', $purchaseRequest) }}" data-validation-context="president-return">
                    @csrf
                    <input type="hidden" name="_validation_context" value="president-return">
                    <input type="hidden" name="decision" value="REJECT">
                    <div class="modal-header"><h2 class="modal-title fs-5">Return to Canvassing?</h2><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body"><p class="small text-muted">Adding a reason helps Purchasing revise the quotation set.</p><label class="form-label" for="president-return-remarks">Remarks <span class="text-muted fw-normal">(optional)</span></label><textarea class="form-control" id="president-return-remarks" name="remarks" rows="4" maxlength="2000">{{ old('_validation_context') === 'president-return' ? old('remarks') : '' }}</textarea><section class="approval-signature-pad mt-3" data-signature-pad aria-labelledby="president-return-signature-heading"><span class="approval-signature-box__label" id="president-return-signature-heading">Electronic signature</span><strong class="approval-signature-box__account">Sign as: {{ auth()->user()->name }}</strong><canvas class="approval-signature-pad__canvas" data-signature-canvas width="760" height="190" tabindex="0" aria-label="Draw your electronic signature"></canvas><input type="hidden" name="signature_name" value="{{ auth()->user()->name }}"><input type="hidden" name="signature_data" data-signature-data><div class="approval-signature-pad__footer"><small>Draw your signature before confirming the return.</small><button class="btn btn-sm btn-outline-primary" type="button" data-signature-clear>Clear signature</button></div><div class="field-validation-message" data-signature-client-error hidden>Draw your signature before confirming this decision.</div>@error('signature_data')<div class="field-validation-message">{{ $message }}</div>@enderror</section></div>
                    <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-warning" type="submit">Return to Canvassing</button></div>
                </form></div>
            </div>
        @endif
    @endcan

    @endif

    @if(! $poFocus && ! $purchaseRequest->purchaseOrder && ! $purchaseRequest->skippedPurchaseOrder() && $purchaseRequest->status === \App\Enums\PurchaseRequestStatus::ForBudgetProcessing)
        @can('prepare', \App\Models\PurchaseOrder::class)
            <section class="card border-0 shadow-sm mb-3" id="purchase-order-actions" aria-labelledby="purchase-order-actions-heading">
                <div class="card-header bg-white fw-semibold" id="purchase-order-actions-heading">Create Purchase Order</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('purchase-orders.store', ['purchaseRequest' => $purchaseRequest, 'from_stage' => $returnStage]) }}" data-validation-context="purchase-order">
                        @csrf
                        <input type="hidden" name="_validation_context" value="purchase-order">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label" for="expected_delivery_date">Expected Delivery Date</label>
                                <input class="form-control" id="expected_delivery_date" name="expected_delivery_date" type="date" value="{{ $purchaseRequest->required_date?->addDays(7)->format('Y-m-d') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="po_remarks">Remarks</label>
                                <textarea class="form-control" id="po_remarks" name="remarks" rows="2" placeholder="Notes for the purchase order"></textarea>
                            </div>
                        </div>
                        <div class="mt-3 d-flex justify-content-between align-items-center">
                            <div class="small text-muted">Approved supplier: {{ $purchaseRequest->selectedSupplier?->name ?? 'Not yet selected' }}</div>
                            <button class="btn btn-primary" type="submit">Generate Purchase Order</button>
                        </div>
                    </form>
                    @include('purchase-requests.partials.skip-purchase-order')
                </div>
            </section>
        @endcan
    @endif


    @if($purchaseRequest->skippedPurchaseOrder())
        <div class="alert alert-light border mb-3 no-print" role="status">
            <strong>No Purchase Order.</strong>
            {{ $purchaseRequest->purchaseOrderSkipper?->name ?? 'Purchasing' }} sent this request to Budget without a PO on {{ $purchaseRequest->purchase_order_skipped_at->format('M j, Y g:i A') }}. Budget and payment use the approved quotation amount.
        </div>
    @endif

    @can('manageBudget', $purchaseRequest)
        @if(! $poFocus && $purchaseRequest->status === \App\Enums\PurchaseRequestStatus::ForBudgetProcessing && ($purchaseRequest->purchaseOrder || $purchaseRequest->skippedPurchaseOrder()) && ! $purchaseRequest->budgetRequest)
            <div class="card action-panel mb-3 no-print">
                <div class="card-header fw-semibold" id="budget-request-actions">Create Budget Request</div>
                <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <p class="mb-0">{{ $purchaseRequest->purchaseOrder ? 'Add this Purchase Order to a Budget Request for Purchasing final approval.' : 'Create the Budget Request from the approved quotation for Purchasing final approval.' }}</p>
                    <a class="btn btn-primary" href="{{ route('budget.create', ['purchase_request_id' => $purchaseRequest->id]) }}">Prepare Budget Request</a>
                </div>
            </div>
        @endif
    @endcan

    @if($purchaseRequest->purchaseOrder)
        @php ($po = $purchaseRequest->purchaseOrder); @endphp
        @can('view', $po)
            <section class="card border-0 shadow-sm mb-3 no-print" aria-labelledby="po-approval-heading">
                <div class="card-header bg-white fw-semibold" id="po-approval-heading">Purchase Order Approval History <span class="badge text-bg-secondary ms-2">{{ str($po->status)->replace('_', ' ') }}</span></div>
                <div class="card-body">
                    @php
                        $stageLabels = [
                            \App\Enums\PurchaseOrderApprovalStage::PreparedBy->value => 'Prepared By',
                            \App\Enums\PurchaseOrderApprovalStage::CheckedBy->value => 'Checked By',
                            \App\Enums\PurchaseOrderApprovalStage::NotedBy->value => 'Noted By',
                            \App\Enums\PurchaseOrderApprovalStage::ApprovedBy->value => 'Approved By',
                        ];
                        $stageInstructions = [
                            \App\Enums\PurchaseOrderApprovalStage::PreparedBy->value => 'Draw your signature. It is saved automatically when you select Move to Checker.',
                            \App\Enums\PurchaseOrderApprovalStage::CheckedBy->value => 'Draw your signature. It is saved automatically when you select Check Purchase Order.',
                            \App\Enums\PurchaseOrderApprovalStage::NotedBy->value => 'Draw your signature. It is saved automatically when you select Note Purchase Order.',
                            \App\Enums\PurchaseOrderApprovalStage::ApprovedBy->value => 'Draw your signature. It is saved automatically when you approve or reject the Purchase Order.',
                        ];
                        $lastRequiredSignatureStage = match ($po->status) {
                            'FOR_CHECKING' => \App\Enums\PurchaseOrderApprovalStage::CheckedBy,
                            'FOR_ACKNOWLEDGEMENT' => \App\Enums\PurchaseOrderApprovalStage::NotedBy,
                            'FOR_APPROVAL' => \App\Enums\PurchaseOrderApprovalStage::ApprovedBy,
                            default => null,
                        };
                        $firstMissingPoSignature = null;
                        if ($lastRequiredSignatureStage !== null) {
                            foreach (\App\Enums\PurchaseOrderApprovalStage::cases() as $requiredStage) {
                                if (! ($po->approvalFor($requiredStage)?->hasValidSignature() ?? false)) {
                                    $firstMissingPoSignature = $requiredStage;
                                    break;
                                }
                                if ($requiredStage === $lastRequiredSignatureStage) {
                                    break;
                                }
                            }
                        }
                        $preparedStageApproval = $po->approvalFor(\App\Enums\PurchaseOrderApprovalStage::PreparedBy);
                        $checkedStageApproval = $po->approvalFor(\App\Enums\PurchaseOrderApprovalStage::CheckedBy);
                        $preparedHandoffComplete = $preparedStageApproval?->status === 'APPROVED'
                            && ($preparedStageApproval?->hasValidSignature() ?? false)
                            && $preparedStageApproval?->signed_at
                            && $preparedStageApproval?->acted_at
                            && $preparedStageApproval->acted_at->gte($preparedStageApproval->signed_at);
                        $viewingAsPreparedAssignee = (int) $preparedStageApproval?->assigned_user_id === (int) auth()->id();
                    @endphp
                    @error('signature_data')
                        <div class="alert alert-danger" role="alert">{{ $message }}</div>
                    @enderror
                    @if($viewingAsPreparedAssignee && $preparedHandoffComplete)
                        <div class="alert alert-success" role="status" data-po-handoff-confirmation>
                            <strong>Submitted to Checker.</strong>
                            You submitted this Purchase Order to {{ $checkedStageApproval?->assignedUser?->name ?? 'the assigned Checker' }} on {{ $preparedStageApproval->acted_at->format('M d, Y g:i A') }}. Your preparation stage is closed and no further action is required from you unless the Purchase Order is returned.
                        </div>
                    @elseif($firstMissingPoSignature)
                        <div class="alert alert-warning" role="status">
                            <strong>{{ $stageLabels[$firstMissingPoSignature->value] }} signature required.</strong>
                            The assigned user, {{ $po->approvalFor($firstMissingPoSignature)?->assignedUser?->name ?? 'not configured' }}, must sign before the Purchase Order can proceed.
                        </div>
                    @endif
                    <div class="po-workflow-signature-list">
                        @forelse(\App\Enums\PurchaseOrderApprovalStage::cases() as $stage)
                            @php
                                $approval = $po->approvalFor($stage);
                                $signatureIsValid = $approval?->hasValidSignature() ?? false;
                            @endphp
                            <article class="po-workflow-signature-stage" data-po-signature-stage="{{ $stage->value }}">
                                <div class="po-workflow-signature-stage__summary">
                                    <div>
                                        <strong>{{ $stageLabels[$stage->value] }}</strong>
                                        <div class="small text-muted">Assigned to {{ $approval?->assignedUser?->name ?? 'PO assignment not configured' }}</div>
                                    </div>
                                    <span class="badge text-bg-{{ $signatureIsValid ? 'success' : (filled($approval?->signature_data) ? 'warning' : 'secondary') }}">{{ $signatureIsValid ? 'Signature saved' : (filled($approval?->signature_data) ? 'Incomplete signature' : 'Not signed') }}</span>
                                </div>

                                @if(filled($approval?->signature_data))
                                    <div class="po-workflow-saved-signature">
                                        <img src="{{ \App\Support\WorkflowSignature::forDisplay($approval->signature_data) }}" alt="{{ $stageLabels[$stage->value] }} saved signature">
                                        <div>
                                            <strong>{{ $approval->signature_name ?: $approval->actor?->name ?: 'Unknown signer' }}</strong>
                                            <small>Signed {{ $approval->signed_at?->format('M d, Y g:i A') ?: 'time not recorded' }}</small>
                                            @if($stage === \App\Enums\PurchaseOrderApprovalStage::PreparedBy && $preparedHandoffComplete)
                                                <small>Submitted to Checker {{ $approval->acted_at->format('M d, Y g:i A') }}</small>
                                            @elseif($approval->acted_at)
                                                <small>Workflow action completed {{ $approval->acted_at->format('M d, Y g:i A') }}</small>
                                            @else
                                                <small>Signature saved; workflow action is still pending.</small>
                                            @endif
                                        </div>
                                    </div>
                                @endif

                                @if(! $signatureIsValid
                                    && (int) $approval?->assigned_user_id === (int) auth()->id()
                                    && $firstMissingPoSignature
                                    && $firstMissingPoSignature !== $stage)
                                    <div class="alert alert-info py-2 mt-3 mb-0" role="status">
                                        Your {{ $stageLabels[$stage->value] }} signature box will be available after the {{ $stageLabels[$firstMissingPoSignature->value] }} signature is saved.
                                    </div>
                                @endif
                                @can('sign', [$po, $stage])
                                    <div class="alert alert-info py-2 mt-3 mb-0" role="status">
                                        Draw your {{ $stageLabels[$stage->value] }} signature in the action section below. It is saved automatically when you submit the action.
                                    </div>
                                @endcan

                                @if(filled($approval?->remarks))
                                    <div class="small text-muted mt-2">{{ $approval->remarks }}</div>
                                @endif
                            </article>
                        @empty
                            <div class="small text-muted">This legacy Purchase Order has no stage-specific approval records.</div>
                        @endforelse
                    </div>
                </div>
            </section>
        @endcan

        @can('view', $po)
        <div class="d-flex flex-wrap gap-2 mb-3 no-print" aria-label="Purchase Order actions">
            @if(! auth()->user()->can('moveToChecker', $po) && auth()->user()->can('sign', [$po, \App\Enums\PurchaseOrderApprovalStage::PreparedBy]))
                <form class="card card-body w-100" method="POST" action="{{ route('purchase-orders.move-to-checker', $purchaseRequest) }}">
                    @csrf
                    <x-workflow-signature-pad
                        id="po-signature-preparedby"
                        label="Prepared By signature"
                        :instruction="$stageInstructions[\App\Enums\PurchaseOrderApprovalStage::PreparedBy->value]"
                        class="mb-3"
                    />
                    <button class="btn btn-primary align-self-start" type="submit">
                        <x-ui.icon name="arrow-right" size="16" /> Move to Checker
                    </button>
                </form>
            @endif
            @can('moveToChecker', $po)
                <form method="POST" action="{{ route('purchase-orders.move-to-checker', $purchaseRequest) }}">
                    @csrf
                    <button class="btn btn-primary" type="submit" id="move-to-checker-btn">
                        <x-ui.icon name="arrow-right" size="16" /> Move to Checker
                    </button>
                </form>
            @endcan
            <button class="btn btn-outline-primary" type="button" data-po-preview-url="{{ route('purchase-orders.preview', $po) }}"><x-ui.icon name="eye" size="16" /> Preview</button>
            @can('update', $po)
                <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#edit-purchase-order" aria-expanded="{{ old('_validation_context') === 'purchase-order-edit' ? 'true' : 'false' }}" aria-controls="edit-purchase-order"><x-ui.icon name="edit" size="16" /> Edit</button>
            @endcan
            @if($po->isApproved())
                <a class="btn btn-outline-secondary" href="{{ route('purchase-orders.document', ['purchaseRequest' => $purchaseRequest, 'mode' => 'print']) }}" target="_blank" rel="noopener"><x-ui.icon name="file-text" size="16" /> Print</a>
            @else
                <button class="btn btn-outline-secondary" type="button" disabled title="Printing is available after final approval"><x-ui.icon name="file-text" size="16" /> Print after approval</button>
            @endif
        </div>
        @can('update', $po)
            <section @class(['collapse mb-3' => true, 'show' => old('_validation_context') === 'purchase-order-edit']) id="edit-purchase-order">
                <form class="card card-body" method="POST" action="{{ route('purchase-orders.update', $purchaseRequest) }}" data-validation-context="purchase-order-edit">
                    @csrf @method('PATCH')
                    <input type="hidden" name="_validation_context" value="purchase-order-edit">
                    <h2 class="h5 mb-3">Edit Purchase Order</h2>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label" for="edit_po_account_number">Payment account number <span class="text-danger">*</span></label><input class="form-control @error('account_number') is-invalid @enderror" id="edit_po_account_number" name="account_number" value="{{ old('account_number', $po->account_number) }}" required>@error('account_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-4"><label class="form-label" for="edit_po_account_name">Payee account name</label><input class="form-control @error('account_name') is-invalid @enderror" id="edit_po_account_name" name="account_name" value="{{ old('account_name', $po->account_name ?: $po->supplier?->name) }}" readonly><small class="text-muted">Saved supplier value.</small>@error('account_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-4"><label class="form-label" for="edit_po_payment_terms">Payment terms</label><input class="form-control @error('payment_terms') is-invalid @enderror" id="edit_po_payment_terms" name="payment_terms" value="{{ old('payment_terms', $po->payment_terms) }}"><small class="text-muted">Saved from the approved quotation when available.</small>@error('payment_terms')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-4"><label class="form-label" for="edit_po_expected_delivery">Expected delivery</label><input class="form-control" id="edit_po_expected_delivery" name="expected_delivery_date" type="date" value="{{ old('expected_delivery_date', $po->expected_delivery_date?->format('Y-m-d')) }}"></div>
                        <div class="col-md-4"><label class="form-label" for="edit_po_transaction_type">Type of transaction</label><select class="form-select" id="edit_po_transaction_type" name="transaction_type">@foreach(['ONLINE' => 'Online Transaction', 'CASH' => 'Cash Transaction', 'CHECK' => 'Check Transaction'] as $value => $label)<option value="{{ $value }}" @selected(old('transaction_type', $po->budget_transaction_type) === $value)>{{ $label }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label" for="edit_po_fund_source">Fund source</label><select class="form-select" id="edit_po_fund_source" name="fund_source">@foreach(['MAIN_FUND' => 'Main Fund', 'CAMPAIGN_FUND' => 'Campaign Fund', 'OTHER' => 'Other'] as $value => $label)<option value="{{ $value }}" @selected(old('fund_source', $po->budget_fund_source) === $value)>{{ $label }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label" for="edit_po_payable_on">When can pay</label><input class="form-control" id="edit_po_payable_on" name="payable_on" type="date" value="{{ old('payable_on', $po->budget_payable_on?->format('Y-m-d')) }}"></div>
                        <div class="col-md-8"><label class="form-label" for="edit_po_payment_details">Payment details</label><textarea class="form-control" id="edit_po_payment_details" name="payment_details" rows="2">{{ old('payment_details', $po->payment_details) }}</textarea></div>
                        <div class="col-12"><label class="form-label" for="edit_po_remarks">Remarks / reason</label><textarea class="form-control" id="edit_po_remarks" name="remarks" rows="2">{{ old('remarks', $po->remarks) }}</textarea></div>
                    </div>
                    <div class="mt-3"><button class="btn btn-primary" type="submit">Save Purchase Order</button></div>
                </form>
            </section>
        @endcan
        @php ($checkedApproval = $po->approvalFor(\App\Enums\PurchaseOrderApprovalStage::CheckedBy)); @endphp
        @can('check', $po)
            <form class="card card-body mb-3 no-print" id="purchase-order-check-actions" method="POST" action="{{ route('purchase-orders.check', $purchaseRequest) }}">
                @csrf
                <h2 class="h5 mb-1">Purchase Order checking</h2>
                <p class="small text-muted mb-3">Check this Purchase Order to send it to the assigned PO Noter.</p>
                <label class="form-label" for="po-check-remarks">Checker remarks</label>
                <textarea class="form-control mb-2" id="po-check-remarks" name="remarks" rows="2"></textarea>
                @php
                    $actionStage = \App\Enums\PurchaseOrderApprovalStage::CheckedBy;
                    $signsWithAction = auth()->user()->can('sign', [$po, $actionStage]);
                    $blockedBySignature = $firstMissingPoSignature !== null && ! ($signsWithAction && $firstMissingPoSignature === $actionStage);
                @endphp
                @if($signsWithAction)
                    <x-workflow-signature-pad
                        :id="'po-signature-'.strtolower($actionStage->name)"
                        :label="$stageLabels[$actionStage->value].' signature'"
                        :instruction="$stageInstructions[$actionStage->value]"
                        class="mb-3"
                    />
                @endif
                @if($blockedBySignature)<div class="alert alert-warning py-2">The {{ $stageLabels[$firstMissingPoSignature->value] }} signature is required before checking this Purchase Order.</div>@endif
                <button class="btn btn-primary align-self-start" @disabled($blockedBySignature)>Check Purchase Order</button>
            </form>
        @endcan
        @php ($notedApproval = $po->approvalFor(\App\Enums\PurchaseOrderApprovalStage::NotedBy)); @endphp
        @can('note', $po)
            <form class="card card-body mb-3 no-print" id="purchase-order-acknowledgement-actions" method="POST" action="{{ route('purchase-orders.acknowledge', $purchaseRequest) }}">
                @csrf
                <h2 class="h5 mb-1">Purchase Order noting</h2>
                <p class="small text-muted mb-3">Note this Purchase Order to send it to the assigned PO Approver.</p>
                <label class="form-label" for="po-note-remarks">Noting remarks</label>
                <textarea class="form-control mb-2" id="po-note-remarks" name="remarks" rows="2"></textarea>
                @php
                    $actionStage = \App\Enums\PurchaseOrderApprovalStage::NotedBy;
                    $signsWithAction = auth()->user()->can('sign', [$po, $actionStage]);
                    $blockedBySignature = $firstMissingPoSignature !== null && ! ($signsWithAction && $firstMissingPoSignature === $actionStage);
                @endphp
                @if($signsWithAction)
                    <x-workflow-signature-pad
                        :id="'po-signature-'.strtolower($actionStage->name)"
                        :label="$stageLabels[$actionStage->value].' signature'"
                        :instruction="$stageInstructions[$actionStage->value]"
                        class="mb-3"
                    />
                @endif
                @if($blockedBySignature)<div class="alert alert-warning py-2">The {{ $stageLabels[$firstMissingPoSignature->value] }} signature is required before noting this Purchase Order.</div>@endif
                <button class="btn btn-primary align-self-start" @disabled($blockedBySignature)>Note Purchase Order</button>
            </form>
        @endcan
        @php ($approvedApproval = $po->approvalFor(\App\Enums\PurchaseOrderApprovalStage::ApprovedBy)); @endphp
        @can('approve', $po)
            <form class="card card-body mb-3 no-print" id="purchase-order-approval-actions" method="POST" action="{{ route('purchase-orders.approve', $purchaseRequest) }}">
                @csrf
                <h2 class="h5 mb-1">Final Purchase Order approval</h2>
                <p class="small text-muted mb-3">Approve this Purchase Order to release it for Budget Request preparation.</p>
                <label class="form-label" for="po-approval-remarks">Final approval remarks</label>
                <textarea class="form-control mb-2" id="po-approval-remarks" name="remarks" rows="2"></textarea>
                @php
                    $actionStage = \App\Enums\PurchaseOrderApprovalStage::ApprovedBy;
                    $signsWithAction = auth()->user()->can('sign', [$po, $actionStage]);
                    $blockedBySignature = $firstMissingPoSignature !== null && ! ($signsWithAction && $firstMissingPoSignature === $actionStage);
                @endphp
                @if($signsWithAction)
                    <x-workflow-signature-pad
                        :id="'po-signature-'.strtolower($actionStage->name)"
                        :label="$stageLabels[$actionStage->value].' signature'"
                        :instruction="$stageInstructions[$actionStage->value]"
                        class="mb-3"
                    />
                @endif
                @if($blockedBySignature)<div class="alert alert-warning py-2">The {{ $stageLabels[$firstMissingPoSignature->value] }} signature is required before approving or rejecting this Purchase Order.</div>@endif
                <button class="btn btn-success me-2" name="decision" value="APPROVE" @disabled($blockedBySignature)>Approve Purchase Order</button>
                <button class="btn btn-outline-danger" name="decision" value="REJECT" @disabled($blockedBySignature)>Reject</button>
            </form>
        @endcan
        @endcan
    @endif
    @can('reviewBudgetFinal', $purchaseRequest)
        @if($purchaseRequest->budgetRequest)
            @php $preparedOrder = $purchaseRequest->purchaseOrder; @endphp
            <div class="card action-panel mb-3 no-print">
                <div class="card-header fw-semibold" id="final-approval-actions">Final budget</div>
                <div class="card-body">
                    <ol class="budget-stepper" aria-label="Budget Request progress">
                        <li class="is-done"><span>1</span>Prepared by Budget Maker</li>
                        <li class="is-current" aria-current="step"><span>2</span>Final budget</li>
                        <li><span>3</span>Payment</li>
                    </ol>
                    <section class="budget-prepared-summary mb-3" aria-labelledby="budget-prepared-heading">
                        <h3 class="budget-prepared-title" id="budget-prepared-heading">Prepared by Budget Maker <span>Read only</span></h3>
                        <dl>
                            <div><dt>Campaign source account</dt><dd>{{ $purchaseRequest->budgetRequest->campaign?->name ?? 'Not assigned' }}<small>{{ $purchaseRequest->budgetRequest->campaign_account_number ?: 'No account number' }}</small></dd></div>
                            <div><dt>PO / Supplier</dt><dd>{{ $preparedOrder?->po_number ?? 'Not recorded' }}<small>{{ $preparedOrder?->supplier?->name ?? 'Supplier not recorded' }}</small></dd></div>
                            <div><dt>Amount</dt><dd class="budget-prepared-amount">&#8369; {{ number_format((float) $purchaseRequest->budgetRequest->amount, 2) }}</dd></div>
                        </dl>
                    </section>
                    <a class="btn btn-primary" href="{{ route('budget.finalize', ['ids' => [$purchaseRequest->budgetRequest->id]]) }}">Final budget &amp; issue BR code</a>
                </div>
            </div>
        @endif
    @endcan

    @can('processPayment', $purchaseRequest)
        @if($purchaseRequest->status === \App\Enums\PurchaseRequestStatus::ForPayment)
            @php
                $hasPayableBudgetRequest = $purchaseRequest->budgetRequest()->exists();
                $payBudget = $purchaseRequest->budgetRequest;
                $paymentAmountDue = \App\Http\Controllers\PaymentController::amountDue($purchaseRequest);
                $paymentsSoFar = $purchaseRequest->payments()->with(['attachments', 'recorder:id,name'])->oldest('created_at')->get();
                $paymentAmountPaid = (float) $paymentsSoFar->sum('amount');
                $paymentBalance = \App\Http\Controllers\PaymentController::balanceDue($purchaseRequest);
                $paymentPaidPercent = $paymentAmountDue > 0 ? min(100, $paymentAmountPaid / $paymentAmountDue * 100) : 0;
                $paymentAmountValue = old('amount', $paymentBalance);
            @endphp
            {{-- In the Record payment window only this card is shown (see embedded-focus.js). --}}
            <section class="card action-panel payment-panel mb-3 no-print" id="payment-actions" aria-labelledby="payment-panel-heading" data-embedded-focus="payment">
                <div class="card-header payment-panel-header">
                    <div>
                        <div class="section-kicker">Finance · Payment</div>
                        <h2 class="h5 mb-0" id="payment-panel-heading">Record payment</h2>
                    </div>
                    <span class="payment-panel-balance">Balance due <strong>&#8369; {{ number_format($paymentBalance, 2) }}</strong></span>
                </div>
                <div class="card-body">
                    <dl class="payment-context">
                        <div><dt>Request</dt><dd>{{ $purchaseRequest->request_number }}<span>{{ $purchaseRequest->title }}</span></dd></div>
                        <div><dt>Purchase Order</dt><dd>
                            @if($purchaseRequest->purchaseOrder)
                                <button class="btn btn-link p-0 payment-context-link" type="button" data-po-preview-url="{{ route('purchase-orders.preview', $purchaseRequest->purchaseOrder) }}">{{ $purchaseRequest->purchaseOrder->po_number }} <x-ui.icon name="eye" size="13" /></button>
                            @else
                                Not created
                            @endif
                            <span>{{ $purchaseRequest->purchaseOrder?->supplier?->name ?? 'Supplier not recorded' }}</span>
                        </dd></div>
                        <div><dt>Budget Request</dt><dd>
                            @if($payBudget)
                                <a class="payment-context-link" href="{{ route('budget-requests.document', $payBudget) }}" target="_blank" rel="noopener">{{ $payBudget->displayNumber() }} <x-ui.icon name="receipt" size="13" /></a>
                            @else
                                Not linked
                            @endif
                            <span>{{ $payBudget ? 'Approved for payment' : 'Required before paying' }}</span>
                        </dd></div>
                    </dl>

                    @unless($hasPayableBudgetRequest)
                        <div class="alert alert-warning d-flex gap-2 align-items-start" role="alert">
                            <x-ui.icon name="warning" size="18" />
                            <div><strong>No Budget Request linked.</strong> A payment must belong to a Budget Request, so this payment cannot be recorded until one is created or linked for this request.</div>
                        </div>
                    @endunless

                    <div class="payment-balance-row">
                        <dl class="payment-balance">
                            <div><dt>Amount due</dt><dd>&#8369; {{ number_format($paymentAmountDue, 2) }}</dd></div>
                            <div><dt>Paid so far</dt><dd>&#8369; {{ number_format($paymentAmountPaid, 2) }}</dd></div>
                            <div class="is-balance"><dt>Balance</dt><dd>&#8369; {{ number_format($paymentBalance, 2) }}</dd></div>
                        </dl>
                        <div class="progress payment-balance-progress" role="progressbar" aria-label="Paid {{ round($paymentPaidPercent) }} percent" aria-valuenow="{{ round($paymentPaidPercent) }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-success" style="width: {{ $paymentPaidPercent }}%"></div></div>
                    </div>

                    @if($paymentsSoFar->isNotEmpty())
                        <details class="payment-history-panel" open>
                            <summary>Partial payments recorded <span class="badge text-bg-light border">{{ $paymentsSoFar->count() }}</span></summary>
                            <ul class="payment-history list-unstyled mb-0">
                                @foreach($paymentsSoFar as $earlierPayment)
                                    <li>
                                        <div><strong>&#8369; {{ number_format((float) $earlierPayment->amount, 2) }}</strong> · {{ $earlierPayment->paid_at?->format('M j, Y') ?? 'No date' }} · {{ str($earlierPayment->payment_method)->replace('_', ' ')->title() }}@if($earlierPayment->reference_number) · Ref {{ $earlierPayment->reference_number }}@endif <span class="text-muted">· {{ $earlierPayment->recorder?->name ?? 'System' }}</span></div>
                                        @if($earlierPayment->attachments->isNotEmpty())
                                            <div class="payment-history-receipts">@foreach($earlierPayment->attachments as $receipt)<a href="{{ route('attachments.download', $receipt) }}"><x-ui.icon name="file-text" size="13" />{{ $receipt->original_name }}</a>@endforeach</div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endif

                    <form method="POST" action="{{ route('payments.store', $purchaseRequest) }}" enctype="multipart/form-data" data-validation-context="payment" data-payment-form data-balance="{{ $paymentBalance }}">
                        @csrf
                        <input type="hidden" name="_validation_context" value="payment">

                        <fieldset class="payment-step">
                            <legend><span class="payment-step-number">1</span>Payment</legend>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label" for="payment-amount">Amount paid now</label>
                                    <div class="input-group has-validation">
                                        <span class="input-group-text">&#8369;</span>
                                        <input class="form-control @error('amount') is-invalid @enderror" id="payment-amount" type="number" step="0.01" min="0.01" max="{{ $paymentBalance }}" name="amount" value="{{ $paymentAmountValue }}" aria-describedby="payment-amount-help" required data-payment-amount>
                                        @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="form-text" id="payment-amount-help">
                                        <button class="btn btn-link btn-sm p-0 align-baseline" type="button" data-payment-fill>Pay full balance</button>
                                        <span data-payment-remaining></span>
                                    </div>
                                </div>
                                <div class="col-md-4"><label class="form-label" for="payment-transaction-type">Type of transaction</label><select class="form-select @error('payment_method') is-invalid @enderror" id="payment-transaction-type" name="payment_method" required><option value="">Select type</option>@foreach(['ONLINE' => 'Online Transaction', 'CASH' => 'Cash Transaction', 'CHECK' => 'Check Transaction', 'COD' => 'Cash on Delivery (COD)'] as $value => $label)<option value="{{ $value }}" @selected(old('payment_method', $payBudget?->payment_method) === $value)>{{ $label }}</option>@endforeach</select>@error('payment_method')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-4"><label class="form-label" for="payment-paid-at">Payment date</label><input class="form-control @error('paid_at') is-invalid @enderror" id="payment-paid-at" type="date" name="paid_at" value="{{ old('paid_at', now()->toDateString()) }}" max="{{ now()->toDateString() }}">@error('paid_at')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-6"><label class="form-label" for="payment-reference">Reference number <span class="text-muted">(optional)</span></label><input class="form-control @error('reference_number') is-invalid @enderror" id="payment-reference" type="text" name="reference_number" maxlength="255" value="{{ old('reference_number') }}" placeholder="Transfer, deposit or check number">@error('reference_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-6"><label class="form-label" for="payment-details">Details <span class="text-muted">(optional)</span></label><input class="form-control @error('details') is-invalid @enderror" id="payment-details" type="text" name="details" maxlength="2000" value="{{ old('details') }}" placeholder="e.g. 50% downpayment">@error('details')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-12">
                                    <label class="form-label" for="payment-receipts">Receipt evidence</label>
                                    <label class="payment-dropzone @error('receipts') is-invalid @enderror @error('receipts.*') is-invalid @enderror" for="payment-receipts" data-payment-dropzone>
                                        <x-ui.icon name="receipt" size="22" />
                                        <span><strong>Choose files</strong> or drop them here</span>
                                        <small>Official receipt, deposit slip, transfer screenshot or check photo · JPG, PNG, WEBP or PDF · up to 5 files, 10 MB each</small>
                                        <input class="payment-dropzone-input" id="payment-receipts" type="file" name="receipts[]" accept=".jpg,.jpeg,.png,.webp,.pdf" multiple required data-payment-receipts>
                                    </label>
                                    <ul class="payment-receipt-list list-unstyled" data-payment-receipt-list aria-live="polite"></ul>
                                    @error('receipts')<div class="field-validation-message">{{ $message }}</div>@enderror
                                    @error('receipts.*')<div class="field-validation-message">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </fieldset>

                        <fieldset class="payment-step">
                            <legend><span class="payment-step-number">2</span>Budget Information <small>Prefilled from the Budget Request · check before saving</small></legend>
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label" for="payment-account-type">Account type</label><select class="form-select @error('account_type') is-invalid @enderror" id="payment-account-type" name="account_type" required><option value="">Select account type</option>@foreach(['ACA' => 'ACA', 'OTHER_PARTY' => 'Other Party', 'OWN_ACCOUNT' => 'Own Account'] as $value => $label)<option value="{{ $value }}" @selected(old('account_type', $payBudget?->account_type) === $value)>{{ $label }}</option>@endforeach</select>@error('account_type')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-4"><label class="form-label" for="payment-fund-source">Fund source</label><select class="form-select @error('fund_source') is-invalid @enderror" id="payment-fund-source" name="fund_source" required><option value="">Select fund source</option>@foreach(['MAIN_FUND' => 'Main Fund', 'CAMPAIGN_FUND' => 'Campaign Fund', 'OTHER' => 'Other'] as $value => $label)<option value="{{ $value }}" @selected(old('fund_source', $payBudget?->fund_source) === $value)>{{ $label }}</option>@endforeach</select>@error('fund_source')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-4"><label class="form-label" for="payment-main-fund">Main fund</label><input class="form-control @error('main_fund') is-invalid @enderror" id="payment-main-fund" name="main_fund" maxlength="255" value="{{ old('main_fund', $payBudget?->main_fund) }}" required>@error('main_fund')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-6">
                                    <label class="form-label" for="payment-budget-custodian">Budget custodian <span class="text-muted">(optional)</span></label>
                                    <select class="form-select @error('budget_custodian_id') is-invalid @enderror" id="payment-budget-custodian" name="budget_custodian_id">
                                        <option value="">Not assigned</option>
                                        @foreach($paymentCustodians as $custodian)
                                            <option value="{{ $custodian->id }}" @selected((string) old('budget_custodian_id', $payBudget?->budget_custodian_id) === (string) $custodian->id)>{{ $custodian->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('budget_custodian_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </fieldset>

                        <fieldset class="payment-step" data-payment-receiving-step>
                            <legend><span class="payment-step-number">3</span>Delivery</legend>
                            <div class="payment-choice" role="radiogroup" aria-label="Delivery status">
                                <label class="payment-choice-option">
                                    <input class="form-check-input" type="radio" name="receiving_status" value="FOR_RECEIVING" data-receiving-choice @checked(old('receiving_status', 'FOR_RECEIVING') === 'FOR_RECEIVING')>
                                    <span><strong>Not yet delivered</strong><small>Moves to For receiving after the balance is paid</small></span>
                                </label>
                                <label class="payment-choice-option">
                                    <input class="form-check-input" type="radio" name="receiving_status" value="RECEIVED" data-receiving-choice @checked(old('receiving_status') === 'RECEIVED')>
                                    <span><strong>Already delivered</strong><small>Add the goods to inventory now</small></span>
                                </label>
                            </div>
                            <p class="payment-partial-note" data-payment-partial-note hidden><x-ui.icon name="hourglass" size="14" />This is a partial payment, so the request stays <strong>For payment</strong> and delivery is recorded with the final payment.</p>
                            <fieldset class="payment-receiving-fields mt-3" data-receiving-fields @if(old('receiving_status') !== 'RECEIVED') hidden disabled @endif>
                                <legend class="fs-6 fw-semibold mb-2">Goods received: add to inventory</legend>
                                @include('purchase-requests.partials.goods-received-fields', ['idPrefix' => 'payment-received'])
                            </fieldset>
                        </fieldset>

                        <div class="payment-submit-bar">
                            <div class="payment-submit-summary" data-payment-summary aria-live="polite">Paying &#8369; {{ number_format((float) $paymentAmountValue, 2) }}</div>
                            <button class="btn btn-success" type="submit" @disabled(! $hasPayableBudgetRequest) data-payment-submit>Record payment</button>
                        </div>
                    </form>
                </div>
            </section>
        @endif
    @endcan

    @can('receiveGoods', $purchaseRequest)
        @if($purchaseRequest->status === \App\Enums\PurchaseRequestStatus::ForReceiving)
            <div class="card action-panel mb-3 no-print" data-embedded-focus="receiving">
                <div class="card-header fw-semibold" id="receiving-actions">Receiving and Inventory</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('receiving.store', $purchaseRequest) }}" data-validation-context="receiving" data-receiving-form>
                        @csrf
                        <input type="hidden" name="_validation_context" value="receiving">
                        @include('purchase-requests.partials.goods-received-fields', ['idPrefix' => 'receiving'])
                        @if(old('_validation_context') === 'receiving' && $errors->any())
                            <div class="alert alert-danger mt-3" role="alert">
                                <strong>Receiving was not recorded.</strong>
                                <ul class="mb-0 mt-2">
                                    @foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach
                                </ul>
                            </div>
                        @endif
                        <div class="alert alert-warning mt-3 mb-0" role="alert" data-receiving-feedback hidden></div>
                        <button class="btn btn-success mt-3" type="submit">Record Receiving and Update Inventory</button>
                    </form>
                </div>
            </div>
        @endif
    @endcan

    @can('reviewOm', $purchaseRequest)
        @if($purchaseRequest->status === \App\Enums\PurchaseRequestStatus::ForOmApproval)
            <div class="card action-panel mb-3 no-print" id="om-actions">
                <div class="card-header fw-semibold">Operations Manager Review</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('om-approvals.approve', $purchaseRequest) }}" data-validation-context="om-review">
                        @csrf
                        <input type="hidden" name="_validation_context" value="om-review">
                        <div class="row g-3 om-review-split">
                            <div class="col-lg-6 om-review-split__remarks">
                                <label class="form-label" for="om-approval-remarks">Remarks <span class="text-muted fw-normal">(optional)</span></label>
                                <textarea class="form-control @error('remarks') is-invalid @enderror" id="om-approval-remarks" name="remarks" rows="3" maxlength="5000" placeholder="Add review notes or explain the correction needed.">{{ in_array(old('_validation_context'), ['om-review', 'om-approve', 'om-return'], true) ? old('remarks') : '' }}</textarea>
                                @error('remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-lg-6 om-review-split__signature">
                                <section class="approval-signature-pad" data-signature-pad aria-labelledby="om-signature-heading">
                                    <span class="approval-signature-box__label" id="om-signature-heading">Electronic signature</span>
                                    <strong class="approval-signature-box__account">Sign as: {{ auth()->user()->name }}</strong>
                                    <canvas class="approval-signature-pad__canvas" data-signature-canvas width="760" height="190" tabindex="0" aria-label="Draw your electronic signature"></canvas>
                                    <input type="hidden" name="signature_name" value="{{ auth()->user()->name }}">
                                    <input type="hidden" name="signature_data" data-signature-data>
                                    <div class="approval-signature-pad__footer"><small>Draw your signature, then choose Proceed to Screening or Return. Your identity and decision time are recorded after submission.</small><button class="btn btn-sm btn-outline-primary" type="button" data-signature-clear>Clear signature</button></div>
                                    <div class="field-validation-message" data-signature-client-error hidden>Draw your signature before confirming this decision.</div>
                                    @error('signature_data')<div class="field-validation-message">{{ $message }}</div>@enderror
                                </section>
                            </div>
                            <div class="col-12 d-flex flex-wrap gap-2">
                                <button class="btn btn-success" type="submit">Sign &amp; Proceed to Screening</button>
                                <button class="btn btn-warning" type="submit" formaction="{{ route('om-approvals.return', $purchaseRequest) }}">Return</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

        @endif
    @endcan

    @can('claim', $purchaseRequest)
        <div class="card action-panel mb-3 no-print" id="screening-claim-actions">
            <div class="card-header fw-semibold">IT / Premises Classification</div>
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <div class="fw-semibold">Claim this request only if it belongs to your department.</div>
                    <div class="small text-muted">Claiming assigns the screening owner without changing the original requestor.</div>
                </div>
                <form method="POST" action="{{ route('request-flow.claim', $purchaseRequest) }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">Claim as {{ auth()->user()->hasRole(\App\Enums\RoleSlug::ItStaff->value) ? 'IT' : 'Premises' }}</button>
                </form>
            </div>
        </div>
    @endcan

    @can('screen', $purchaseRequest)
        <div class="card action-panel mb-3 no-print" id="screening-actions">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center gap-2">
                <span>{{ $requestTypeLabel }} Team Screening</span>
                @can('unclaim', $purchaseRequest)
                    <form method="POST" action="{{ route('request-flow.unclaim', $purchaseRequest) }}" onsubmit="return confirm('Unclaim this request and return it to the screening queue?');">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-sm btn-outline-secondary" type="submit">Unclaim request</button>
                    </form>
                @endcan
            </div>
            <div class="card-body">
                <p class="text-muted">Check available inventory. Send available items to Inventory for release, or mark them unavailable to continue to Purchasing.</p>
                @include('purchase-requests.partials.available-inventory-screening')
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#unavailableModal">Inventory Unavailable</button>
                    @if($purchaseRequest->request_type === 'PREMISES')
                        <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#importModal">Import Premises Excel Export</button>
                    @endif
                </div>
            </div>
        </div>

        <div class="modal fade" id="unavailableModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('request-flow.screening.store', $purchaseRequest) }}" enctype="multipart/form-data" data-validation-context="screening-unavailable">
                @csrf<input type="hidden" name="_validation_context" value="screening-unavailable"><input type="hidden" name="decision" value="UNAVAILABLE">
                <div class="modal-header"><h2 class="modal-title fs-5">Inventory Unavailable</h2><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label" for="unavailable-evidence">Screening remarks <span class="text-muted fw-normal">(optional)</span></label><textarea class="form-control" id="unavailable-evidence" name="evidence" rows="4" maxlength="5000"></textarea></div>
                    <section class="approval-signature-pad mt-3" data-signature-pad aria-labelledby="unavailable-signature-heading">
                        <span class="approval-signature-box__label" id="unavailable-signature-heading">Electronic signature</span>
                        <strong class="approval-signature-box__account">Sign as: {{ auth()->user()->name }}</strong>
                        <canvas class="approval-signature-pad__canvas" data-signature-canvas width="760" height="190" tabindex="0" aria-label="Draw your electronic signature"></canvas>
                        <input type="hidden" name="signature_name" value="{{ auth()->user()->name }}">
                        <input type="hidden" name="signature_data" data-signature-data>
                        <div class="approval-signature-pad__footer"><small>Draw your signature in the box, then select Add a Quotation. Your identity and approval time are recorded only after a successful submission.</small><button class="btn btn-sm btn-outline-primary" type="button" data-signature-clear>Clear signature</button></div>
                        <div class="field-validation-message" data-signature-client-error hidden>Draw your signature before confirming this decision.</div>
                        @error('signature_data')<div class="field-validation-message">{{ $message }}</div>@enderror
                    </section>
                    <div><label class="form-label" for="quotation-evidence">Quotation or canvassing evidence (optional)</label><input class="form-control" id="quotation-evidence" name="quotation_evidence" type="file" accept=".pdf,.jpg,.jpeg,.png,.csv,.xls,.xlsx"></div>
                </div>
                <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" type="submit">Add a Quotation</button></div>
            </form></div>
        </div>

        @if($purchaseRequest->request_type === 'PREMISES')
            <div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('request-flow.screening.store', $purchaseRequest) }}" enctype="multipart/form-data" data-validation-context="screening-import">
                    @csrf<input type="hidden" name="_validation_context" value="screening-import"><input type="hidden" name="decision" value="AVAILABLE">
                    <div class="modal-header"><h2 class="modal-title fs-5">Import Premises Excel Export</h2><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <p class="small text-muted">Export the Excel sheet as CSV. Required columns: asset_tag, item_code, description, serial_number, location, release_date, recipient.</p>
                        <label class="form-label" for="premises-import">Premises asset CSV</label><input class="form-control" id="premises-import" name="premises_import" type="file" accept=".csv" required>
                        <x-workflow-signature-pad
                            id="premises-import-signature"
                            label="Screening signature"
                            instruction="Draw your signature, then select Import and Record Release."
                            class="mt-3"
                        />
                    </div>
                    <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Import and Record Release</button></div>
                </form></div>
            </div>
        @endif
    @endcan

    @can('releaseInventory', $purchaseRequest)
        @include('purchase-requests.partials.inventory-release')
    @endcan

    <div class="po-focus-history">
        <div>
            <details class="card workflow-history-disclosure">
                <summary>
                    <span><strong>Approval History</strong><small>{{ $purchaseRequest->approvals->count() }} approval {{ str('entry')->plural($purchaseRequest->approvals->count()) }}</small></span>
                    <span class="workflow-history-toggle"><span class="when-closed">Expand</span><span class="when-open">See less</span></span>
                </summary>
                <div class="card-body workflow-timeline">
                    @forelse($purchaseRequest->approvals->sortByDesc('acted_at') as $approval)
                        <div class="workflow-entry"><div class="fw-semibold">{{ str($approval->action)->replace('_', ' ')->title() }}</div><div class="small text-muted">{{ $approval->user->name }} &middot; {{ $approval->acted_at->format('M d, Y g:i A') }}</div>@if($approval->remarks)<div class="small mt-1">{{ $approval->remarks }}</div>@endif</div>
                    @empty<x-ui.empty-state icon="flow" title="No approval history" description="Approval decisions will appear here as the request moves through workflow." />@endforelse
                </div>
            </details>
        </div>
        <div>
            <details class="card workflow-history-disclosure">
                <summary>
                    <span><strong>Workflow and Audit Timeline</strong><small>{{ $purchaseRequest->auditLogs->count() }} workflow {{ str('entry')->plural($purchaseRequest->auditLogs->count()) }}</small></span>
                    <span class="workflow-history-toggle"><span class="when-closed">Expand</span><span class="when-open">See less</span></span>
                </summary>
                <div class="card-body workflow-timeline">
                    <div class="workflow-timeline-legend mb-3" aria-label="Timeline color legend"><span><i class="workflow-timeline-key workflow-timeline-key--completed"></i>Completed</span><span><i class="workflow-timeline-key workflow-timeline-key--current"></i>Current status</span></div>
                    @forelse($purchaseRequest->auditLogs->sortByDesc('created_at') as $log)
                        @php ($timelineState = $loop->first ? 'current' : 'completed'); @endphp
                        <div class="workflow-entry workflow-entry--{{ $timelineState }}"><div class="d-flex justify-content-between gap-2"><strong>{{ str($log->action)->replace('_', ' ')->title() }}</strong><div class="d-flex flex-wrap justify-content-end align-items-center gap-2"><span class="workflow-entry-state workflow-entry-state--{{ $timelineState }}">{{ $timelineState === 'current' ? 'Current status' : 'Completed' }}</span><span class="small text-muted">{{ $log->created_at->format('M d, Y g:i A') }}</span></div></div><div class="small text-muted">{{ $log->user?->name ?? 'System' }}</div><div class="small">{{ $log->previous_status ? str($log->previous_status)->replace('_', ' ')->title() : 'New' }} &rarr; {{ str($log->new_status)->replace('_', ' ')->title() }}</div>@if($log->remarks)<div class="small mt-1">{{ $log->remarks }}</div>@endif</div>
                    @empty<x-ui.empty-state icon="logs" title="No recent activity" description="Workflow and audit events will appear here." />@endforelse
                </div>
            </details>
        </div>
    </div>
    @if($poFocus)
        @include('purchase-requests.partials.purchase-order-request-details')
    @endif
    </div>
    @include('purchase-requests.partials.purchase-order-print-summary')
    @endif

@endsection

@push('scripts')
<script>
    (() => {
        const addButton = document.getElementById('add-quotation');
        const container = document.getElementById('quotation-rows');
        const entryBody = document.getElementById('quotation-entry-body');
        if (! addButton || ! container) return;

        const quotationTemplate = container.querySelector('[data-quotation-row]')?.cloneNode(true);
        const emptyState = document.getElementById('quotation-empty-state');
        const submitButton = document.querySelector('[data-submit-quotations]');
        if (! quotationTemplate) return;

        let nextQuoteIndex = Math.max(
            ...[...container.querySelectorAll('[data-quotation-row]')]
                .map((row) => Number(row.dataset.quoteIndex || 0)),
        ) + 1;

        function syncRowNumbers() {
            const rows = container.querySelectorAll('[data-quotation-row]');
            rows.forEach((row, index) => {
                const number = row.querySelector('[data-quotation-number]');
                if (number) number.textContent = index + 1;
            });
            emptyState?.classList.toggle('d-none', rows.length > 0);
            if (submitButton) submitButton.disabled = rows.length === 0;
        }

        function syncBulkTotal(row) {
            let total = 0;
            row.querySelectorAll('[data-quoted-item]').forEach((item) => {
                total += Number(item.querySelector('.bulk-item-quantity')?.value || 0) * Number(item.querySelector('.bulk-item-price')?.value || 0);
            });
            const output = row.querySelector('[data-bulk-total]');
            if (output) output.textContent = total.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function syncQuotedItemActions(row) {
            const items = row.querySelectorAll('[data-quoted-item]');
            items.forEach((item) => {
                const button = item.querySelector('[data-remove-quoted-item]');
                if (! button) return;
                const clearsOnlyRow = items.length === 1;
                button.textContent = clearsOnlyRow ? 'Clear' : 'Remove';
                button.setAttribute('aria-label', clearsOnlyRow ? 'Clear requested item' : 'Remove requested item');
                button.classList.toggle('btn-outline-secondary', clearsOnlyRow);
                button.classList.toggle('btn-outline-danger', ! clearsOnlyRow);
            });
        }

        function syncQuotationMode() {
            const mode = document.querySelector('input[name="quotation_mode"]:checked')?.value || 'manual';
            document.querySelectorAll('[data-quotation-row]').forEach((row) => {
                const manualFields = row.querySelectorAll('.quote-manual-fields input, .quote-manual-fields textarea, .quote-manual-fields select');
                const bulkFields = row.querySelectorAll('.quote-bulk-fields input, .quote-bulk-fields textarea, .quote-bulk-fields select, .quote-bulk-fields button[data-remove-quoted-item]');

                row.querySelectorAll('.quote-manual-fields').forEach((field) => field.classList.toggle('d-none', mode !== 'manual'));
                row.querySelectorAll('.quote-bulk-fields').forEach((field) => field.classList.toggle('d-none', mode !== 'bulk'));

                manualFields.forEach((field) => {
                    field.disabled = mode !== 'manual';
                });
                bulkFields.forEach((field) => {
                    field.disabled = mode !== 'bulk';
                });

                syncQuotedItemActions(row);
                syncBulkTotal(row);
            });
        }

        addButton.addEventListener('click', () => {
            if (entryBody?.classList.contains('d-none')) {
                entryBody.classList.remove('d-none');
                addButton.setAttribute('aria-expanded', 'true');
                entryBody.querySelector('input, select, textarea')?.focus();
                return;
            }

            const clonedRow = quotationTemplate.cloneNode(true);
            const nextIndex = nextQuoteIndex++;
            clonedRow.dataset.quoteIndex = nextIndex;
            clonedRow.querySelectorAll('[name]').forEach((element) => {
                element.name = element.name.replace(/quotations\[\d+\]/, `quotations[${nextIndex}]`);
            });
            clonedRow.querySelectorAll('input, textarea, select').forEach((element) => {
                if (element.tagName === 'SELECT') {
                    element.selectedIndex = 0;
                    element.disabled = false;
                } else if (element.type === 'checkbox') {
                    element.checked = false;
                } else {
                    element.value = '';
                }
            });
            clonedRow.querySelectorAll('[data-remove-quoted-item]').forEach((button) => {
                button.disabled = false;
            });
            clonedRow.querySelectorAll('[data-quoted-item]').forEach((item, itemIndex) => {
                item.querySelectorAll('[name]').forEach((element) => {
                    element.name = element.name.replace(/\[items\]\[\d+\]/, `[items][${itemIndex}]`);
                });
            });
            container.appendChild(clonedRow);
            syncRowNumbers();
            syncQuotationMode();
        });

        container.addEventListener('click', event => {
            const button = event.target.closest('[data-remove-quotation]');
            if (! button) return;
            button.closest('[data-quotation-row]').remove();
            syncRowNumbers();
        });

        container.addEventListener('click', event => {
            const button = event.target.closest('[data-add-quoted-item]');
            if (! button) return;
            const row = button.closest('[data-quotation-row]');
            const items = row.querySelector('[data-quoted-items]');
            const firstItem = items.querySelector('[data-quoted-item]');
            const itemIndex = items.querySelectorAll('[data-quoted-item]').length;
            const clone = firstItem.cloneNode(true);
            clone.querySelectorAll('[name]').forEach((element) => {
                element.name = element.name.replace(/\[items\]\[\d+\]/, `[items][${itemIndex}]`);
                if (element.tagName === 'SELECT') element.selectedIndex = 0;
                else element.value = element.classList.contains('bulk-item-quantity') ? '1' : '0';
            });
            items.appendChild(clone);
            syncQuotedItemActions(row);
            syncBulkTotal(row);
        });

        container.addEventListener('click', event => {
            const button = event.target.closest('[data-remove-quoted-item]');
            if (! button) return;
            const items = button.closest('[data-quoted-items]');
            const rows = items.querySelectorAll('[data-quoted-item]');
            if (rows.length <= 1) {
                const item = rows[0];
                const select = item.querySelector('select');
                const quantity = item.querySelector('.bulk-item-quantity');
                const price = item.querySelector('.bulk-item-price');
                if (select) select.selectedIndex = 0;
                if (quantity) quantity.value = '';
                if (price) price.value = '0';
                syncBulkTotal(button.closest('[data-quotation-row]'));
                return;
            }
            button.closest('[data-quoted-item]').remove();
            items.querySelectorAll('[data-quoted-item]').forEach((item, itemIndex) => {
                item.querySelectorAll('[name]').forEach((element) => {
                    element.name = element.name.replace(/\[items\]\[\d+\]/, `[items][${itemIndex}]`);
                });
            });
            const quotationRow = button.closest('[data-quotation-row]');
            syncQuotedItemActions(quotationRow);
            syncBulkTotal(quotationRow);
        });

        container.addEventListener('input', event => {
            if (event.target.matches('.bulk-item-quantity, .bulk-item-price')) syncBulkTotal(event.target.closest('[data-quotation-row]'));
        });

        document.querySelectorAll('input[name="quotation_mode"]').forEach((radio) => radio.addEventListener('change', syncQuotationMode));
        container.addEventListener('change', event => {
            if (! event.target.matches('[data-recommended-quotation]') || ! event.target.checked) return;
            container.querySelectorAll('[data-recommended-quotation]').forEach((checkbox) => {
                if (checkbox !== event.target) checkbox.checked = false;
            });
        });
        const quotationForm = container.closest('[data-quotation-form]');
        quotationForm?.addEventListener('submit', (event) => {
            const summary = quotationForm.querySelector('[data-quotation-errors]');
            const errorList = quotationForm.querySelector('[data-quotation-error-list]');
            const errors = [];
            let firstInvalid = null;

            quotationForm.querySelectorAll('[data-quotation-field-error]').forEach((message) => message.remove());
            quotationForm.querySelectorAll('.is-invalid').forEach((field) => {
                field.classList.remove('is-invalid');
                field.removeAttribute('aria-invalid');
            });

            const rows = [...container.querySelectorAll('[data-quotation-row]')];
            const activeRows = rows.filter((row) => row.querySelector('[name$="[supplier_name]"]')?.value.trim()
                || row.querySelector('[name$="[quoted_amount]"]')?.value.trim()
                || row.querySelector('[name$="[delivery_lead_time]"]')?.value.trim()
                || row.querySelector('[name$="[warranty]"]')?.value.trim()
                || row.querySelector('[name$="[quotation_url]"]')?.value.trim()
                || row.querySelector('[name$="[bank_details]"]')?.value.trim()
                || row.querySelector('[name$="[remarks]"]')?.value.trim()
                || row.querySelector('[data-recommended-quotation]')?.checked
                || [...row.querySelectorAll('[name$="[item_id]"]')].some((field) => field.value)
                || [...row.querySelectorAll('.bulk-item-quantity')].some(field => field.value && field.value !== '1')
                || [...row.querySelectorAll('.bulk-item-price')].some(field => field.value && field.value !== '0'));

            const confirmation = quotationForm.querySelector('[data-confirm-without-quotation]');
            if (confirmation) confirmation.value = '0';
            if (!activeRows.length) {
                summary?.classList.add('d-none');
                const code = quotationForm.dataset.requestCode;
                const nextStage = quotationForm.dataset.nextStage;
                if (!window.confirm(`Request ${code}: proceed to ${nextStage} without adding a supplier quotation? Existing saved quotations will be kept.`)) {
                    event.preventDefault();
                    return;
                }
                if (confirmation) confirmation.value = '1';
                // Omit default VAT, location and bulk controls from genuinely empty rows.
                rows.forEach(row => row.querySelectorAll('[name]').forEach(field => field.disabled = true));
                return;
            }

            const flagField = (field, rowNumber) => {
                const wrapper = field.closest('[class*="col-"]') || field.parentElement;
                const label = wrapper?.querySelector('.form-label')?.textContent.replace('*', '').trim() || 'Field';
                const message = `${label} is required or has an invalid value.`;
                errors.push(`Quotation ${rowNumber}: ${message}`);
                field.classList.add('is-invalid');
                field.setAttribute('aria-invalid', 'true');
                const feedback = document.createElement('div');
                feedback.className = 'invalid-feedback d-block';
                feedback.dataset.quotationFieldError = 'true';
                feedback.textContent = message;
                wrapper?.appendChild(feedback);
                firstInvalid ??= field;
            };

            activeRows.forEach((row, index) => {
                row.querySelectorAll('input[required], select[required], textarea[required]').forEach((field) => {
                    if (field.disabled || field.type === 'checkbox') return;
                    if (!field.value.trim() || !field.checkValidity()) flagField(field, index + 1);
                });
            });

            const recommendationBoxes = [...quotationForm.querySelectorAll('[data-recommended-quotation]')];
            if (recommendationBoxes.length && !activeRows.some((row) => row.querySelector('[data-recommended-quotation]')?.checked)) {
                errors.push('Select one supplier as the purchasing recommendation.');
                firstInvalid ??= recommendationBoxes[0];
            }

            if (!errors.length) {
                summary?.classList.add('d-none');
                return;
            }

            event.preventDefault();
            if (errorList) {
                errorList.replaceChildren();
                errors.forEach((message) => {
                    const item = document.createElement('li');
                    item.textContent = message;
                    errorList.appendChild(item);
                });
            }
            summary?.classList.remove('d-none');
            summary?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            summary?.focus({ preventScroll: true });
            firstInvalid?.focus({ preventScroll: true });
        });

        syncRowNumbers();
        syncQuotationMode();
    })();

    (() => {
        const printButton = document.querySelector('[data-print-budget-request]');
        if (! printButton) return;
        printButton.addEventListener('click', () => {
            document.body.classList.add('print-budget-request');
            window.print();
        });
        window.addEventListener('afterprint', () => document.body.classList.remove('print-budget-request'));
    })();

    @if(session('quotation_edit_failed'))
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.getElementById('quotation-edit-{{ (int) session('quotation_edit_failed') }}');
            if (modal && window.bootstrap?.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modal).show();
            }
        });
    @endif
</script>
@endpush
