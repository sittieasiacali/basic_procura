@php
    $canEditDetails = in_array(\App\Enums\PurchaseOrderApprovalStage::PreparedBy, $editableStages, true);
    $isLocked = $purchaseOrder->isLockedForTaskEdits();
@endphp

<div class="po-task-edit" data-po-task-edit>
    @if($isLocked)
        <div class="alert alert-secondary py-2" role="status">This Purchase Order is already part of a Budget Request, so its tasks can no longer be edited.</div>
    @elseif($editableStages === [])
        <div class="alert alert-secondary py-2" role="status">You have no completed task on this Purchase Order that you can edit.</div>
    @endif

    @if($editableStages !== [])
        <form method="POST" action="{{ route('purchase-orders.task.update', $purchaseOrder->purchaseRequest) }}" data-po-task-edit-form>
            @csrf
            @method('PATCH')
            <div class="alert alert-danger py-2 d-none" role="alert" data-po-task-edit-errors></div>
            <div class="alert alert-success py-2 d-none" role="status" data-po-task-edit-success></div>

            @if($canEditDetails)
                <h3 class="h6 mb-2">Prepared By — Purchase Order details</h3>
                <div class="row g-3 mb-3">
                    <div class="col-md-6"><label class="form-label" for="po_task_account_number">Payment account number <span class="text-danger">*</span></label><input class="form-control" id="po_task_account_number" name="account_number" value="{{ $purchaseOrder->account_number }}" maxlength="255" required></div>
                    <div class="col-md-6"><label class="form-label" for="po_task_payment_terms">Payment terms <span class="text-danger">*</span></label><input class="form-control" id="po_task_payment_terms" name="payment_terms" value="{{ $purchaseOrder->payment_terms }}" maxlength="255" required></div>
                    <div class="col-md-6"><label class="form-label" for="po_task_expected_delivery">Expected delivery</label><input class="form-control" id="po_task_expected_delivery" name="expected_delivery_date" type="date" value="{{ $purchaseOrder->expected_delivery_date?->format('Y-m-d') }}"></div>
                    <div class="col-12"><label class="form-label" for="po_task_payment_details">Payment details</label><textarea class="form-control" id="po_task_payment_details" name="payment_details" rows="2" maxlength="2000">{{ $purchaseOrder->payment_details }}</textarea></div>
                    <div class="col-12"><label class="form-label" for="po_task_remarks">Remarks</label><textarea class="form-control" id="po_task_remarks" name="remarks" rows="2" maxlength="2000">{{ $purchaseOrder->remarks }}</textarea></div>
                </div>
            @endif
        @endif

        <h3 class="h6 mb-2">Workflow tasks</h3>
        <div class="list-group mb-3">
            @foreach(\App\Enums\PurchaseOrderApprovalStage::cases() as $stage)
                @php
                    $approval = $purchaseOrder->approvalFor($stage);
                    $editable = in_array($stage, $editableStages, true) && $stage !== \App\Enums\PurchaseOrderApprovalStage::PreparedBy;
                @endphp
                <div class="list-group-item">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <strong>{{ $stageLabels[$stage->value] }}</strong>
                        <span class="small text-muted">
                            {{ $approval?->actor?->name ?? $approval?->assignedUser?->name ?? 'Not assigned' }}
                            · {{ $approval?->acted_at ? str($approval->status)->title().' '.$approval->acted_at->format('M d, Y g:i A') : 'Pending' }}
                        </span>
                    </div>
                    @if($editable)
                        <label class="form-label small mt-2 mb-1" for="po_task_stage_{{ $stage->value }}">Your remarks</label>
                        <textarea class="form-control" id="po_task_stage_{{ $stage->value }}" name="stage_remarks[{{ $stage->value }}]" rows="2" maxlength="2000">{{ $approval?->remarks }}</textarea>
                    @else
                        <div class="small mt-1 {{ filled($approval?->remarks) ? '' : 'text-muted' }}">{{ $approval?->remarks ?: 'N/A' }}</div>
                    @endif
                </div>
            @endforeach
        </div>

    @if($editableStages !== [])
            <button class="btn btn-primary" type="submit">Save changes</button>
        </form>
    @endif

    <h3 class="h6 mt-4 mb-2">Edit history</h3>
    @if($history->isEmpty())
        <p class="small text-muted mb-0">No edits have been made to this Purchase Order.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>When</th><th>Edited by</th><th>Field</th><th>From</th><th>To</th></tr></thead>
                <tbody>
                    @foreach($history as $log)
                        @foreach($log->metadata['changes'] ?? [] as $change)
                            <tr>
                                <td class="small text-nowrap">{{ $log->created_at?->format('M d, Y g:i A') }}</td>
                                <td class="small">{{ $log->user?->name ?? 'Unknown user' }}</td>
                                <td class="small">{{ $change['field'] ?? '' }}</td>
                                <td class="small text-muted">{{ $change['from'] ?? '—' }}</td>
                                <td class="small">{{ $change['to'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
